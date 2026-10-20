<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Persistent private-account cooldown; retries and process restarts cannot bypass an upstream limit. */
final class ModelCooldown
{
    private const SCOPE = 'private-chatgpt';

    /** Read the shared delay without taking a writer lock; active tools do not use this gate. */
    public static function remainingSeconds(): int
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_model_cooldowns')->where('scope', self::SCOPE)->find();
        if ($row === false) throw new GatewayError('storage_unavailable', 'The shared rate-limit state could not be read.', 503);
        return is_array($row) ? max(0, (int) $row['until_at'] - time()) : 0;
    }

    /**
     * Pace browser mutations after a limit, atomically across workers.
     * Returns seconds to wait, or zero for one dispatch; no lock is held while waiting.
     * Healthy traffic outside the recovery window remains concurrent.
     */
    public static function dispatchDelay(): int
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_model_cooldowns')->where('scope', self::SCOPE)->find();
        if ($row === false) throw new GatewayError('storage_unavailable', 'The shared rate-limit state could not be read.', 503);
        if (!is_array($row) || max((int) $row['until_at'], (int) $row['updated_at']) < time() - 900) return 0;
        $delay = max((int) $row['until_at'], (int) $row['next_probe_at']) - time();
        if ($delay > 0) return $delay;
        return Schema::transaction(static function (): int {
            $db = SDB(null, 'medoo', 'sqlite');
            $row = $db->table('gateway_model_cooldowns')->where('scope', self::SCOPE)->find();
            if ($row === false) throw new GatewayError('storage_unavailable', 'The shared rate-limit state could not be read.', 503);
            if (!is_array($row)) return 0;
            $now = time();
            $delay = max((int) $row['until_at'], (int) $row['next_probe_at']) - $now;
            if ($delay > 0) return $delay;
            $write = $db->table('gateway_model_cooldowns')->where('scope', self::SCOPE)->update(['next_probe_at' => $now + 5]);
            if (!$write || $write->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'The shared rate-limit state could not be saved.', 503);
            return 0;
        });
    }

    /** Parse Retry-After as seconds or an HTTP date; never shorten a valid server delay. */
    public static function retryAfter(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'retry-after' || !is_string($value) || strlen($value) > 64) continue;
            $value = trim($value);
            // Huge valid delays exceed any turn lifetime. Defer beyond that lifetime
            // without overflowing an integer or retrying sooner through a clamp.
            if (ctype_digit($value)) return strlen(ltrim($value, '0')) > 9
                ? intdiv(PHP_INT_MAX, 4) : (int)$value;
            $date = strtotime($value);
            if ($date !== false) return max(0, $date - time());
        }
        return null;
    }

    /** Recognize explicit non-transient API error codes; never classify transcript prose. */
    public static function requiresUserAction(string $body): bool
    {
        if (strlen($body) > 65536) return false;
        $data = json_decode($body, true);
        if (!is_array($data)) return false;
        $error = is_array($data['error'] ?? null) ? $data['error']
            : (is_array($data['detail'] ?? null) ? $data['detail'] : []);
        return count(array_intersect([(string)($error['code'] ?? ''), (string)($error['type'] ?? '')], [
            'insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active',
            'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded',
        ])) > 0;
    }

    /**
     * Persist one verified upstream 429 before any other task may dispatch.
     * A repeated observation is idempotent. Concurrent failures share one backoff level;
     * a new failure after cooldown increases it, with a small deterministic jitter.
     * @return int Absolute earliest retry time, including the server's minimum delay.
     */
    public static function observeRateLimit(string $eventKey, array $headers = []): int
    {
        $key = hash('sha256', $eventKey);
        $serverDelay = self::retryAfter($headers);
        return Schema::transaction(static function () use ($key, $serverDelay): int {
            $db = SDB(null, 'medoo', 'sqlite');
            $row = $db->table('gateway_model_cooldowns')->where('scope', self::SCOPE)->find();
            if ($row === false) throw new GatewayError('storage_unavailable', 'The shared rate-limit state could not be read.', 503);
            if (is_array($row) && ($row['last_event_key'] ?? '') === $key) return (int) $row['until_at'];
            $now = time();
            $recent = is_array($row) && max((int) $row['until_at'], (int) $row['updated_at']) >= $now - 900;
            $attempt = $recent ? max(1, (int) $row['retry_count']) : 1;
            if ($recent && $now >= (int) $row['until_at']) $attempt = min(5, $attempt + 1);
            $base = $serverDelay ?? min(300, 30 * (2 ** ($attempt - 1)));
            $jitter = hexdec(substr($key, 0, 4)) % (min(15, max(1, (int) ($base / 10))) + 1);
            $until = max($now + $base + $jitter, (int) ($row['until_at'] ?? 0));
            $values = ['until_at' => $until, 'error_code' => 'CHATGPT_RATE_LIMITED',
                'updated_at' => $now, 'retry_count' => $attempt, 'last_event_key' => $key,
                'next_probe_at' => max($until, (int) ($row['next_probe_at'] ?? 0))];
            $write = is_array($row) ? $db->table('gateway_model_cooldowns')->where('scope', self::SCOPE)->update($values)
                : $db->table('gateway_model_cooldowns')->add(['scope' => self::SCOPE] + $values);
            if (!$write || $write->rowCount() !== 1) throw new GatewayError('storage_unavailable', 'The shared rate-limit state could not be saved.', 503);
            return $until;
        });
    }

    /**
     * Extend an account-wide throttle; Pro-only exhaustion does not block other models.
     * @param string $code Stable upstream failure code.
     * @param int $seconds Cooldown duration, bounded to one second through one day.
     * @return void
     * @throws \Throwable If the existing shared cooldown cannot be persisted.
     */
    public static function record(string $code, int $seconds = 60): void
    {
        // Pro-only exhaustion leaves the account's other model/effort routes available.
        if ($code === 'CHATGPT_PRO_QUOTA_EXHAUSTED') return;
        if (!preg_match('/(?:RATE_LIMIT|QUOTA|TOO_MANY_REQUESTS)/i', $code)) return;
        Schema::transaction(static function () use ($code, $seconds): void {
            $db = SDB(null, 'medoo', 'sqlite');
            $row = $db->table('gateway_model_cooldowns')->where('scope', 'private-chatgpt')->find();
            $values = ['until_at' => max(time() + max(1, min($seconds, 86400)), (int) ($row['until_at'] ?? 0)),
                'error_code' => $code, 'updated_at' => time()];
            if (is_array($row)) $db->table('gateway_model_cooldowns')->where('scope', 'private-chatgpt')->update($values);
            else $db->table('gateway_model_cooldowns')->add(['scope' => 'private-chatgpt'] + $values);
        });
    }
}
