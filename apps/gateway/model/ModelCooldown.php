<?php
declare(strict_types=1);
namespace app\gateway\model;

use app\gateway\database\Schema;

/** Persistent private-account cooldown; retries and process restarts cannot bypass an upstream limit. */
final class ModelCooldown
{
    /** Refuse a new browser submission while the account's known cooldown remains active. */
    public static function assertReady(): void
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_model_cooldowns')->where('scope', 'private-chatgpt')->find();
        if (is_array($row) && (int) $row['until_at'] > time()) {
            throw new GatewayError('rate_limit_exceeded', 'ChatGPT 暂时限流，请在 ' . ((int) $row['until_at'] - time()) . ' 秒后继续；已保留任务上下文。', 429);
        }
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
