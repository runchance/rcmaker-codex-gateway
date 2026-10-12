<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;
use app\gateway\database\Schema;

/** Tracks login renewal reminders only; profile deletion always requires a separate console confirmation. */
final class BrowserLoginLifecycle
{
    public const RENEW_AFTER_SECONDS = 2 * 86400;
    public const SNOOZE_SECONDS = 86400;

    /**
     * Identify a ChatGPT-authenticated Codex account without using token contents as its identity.
     * Token refresh, malformed writes, logout and API-key mode cannot manufacture an account change.
     * @param array $auth Parsed local Codex auth.json, retained only for this call.
     * @return string|null SHA-256 of account and user identifiers, or no usable observation.
     */
    public static function fingerprint(#[\SensitiveParameter] array $auth): ?string
    {
        $tokens = $auth['tokens'] ?? null;
        if (!is_array($tokens)) return null;
        foreach (['id_token', 'access_token'] as $name) {
            $jwt = $tokens[$name] ?? null;
            if (!is_string($jwt)) continue;
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) continue;
            $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if (!is_string($payload)) continue;
            $claims = json_decode($payload, true);
            $identity = is_array($claims) ? ($claims['https://api.openai.com/auth'] ?? null) : null;
            if (!is_array($identity)) continue;
            $account = $tokens['account_id'] ?? $identity['chatgpt_account_id'] ?? null;
            $user = $identity['chatgpt_user_id'] ?? null;
            if (!is_string($account) || $account === '' || strlen($account) > 256
                || !is_string($user) || $user === '' || strlen($user) > 256) continue;
            return hash('sha256', $account . "\0" . $user);
        }
        return null;
    }

    /**
     * Read the same configured Codex home as the integration. Missing/keyring-only auth is unknown, not a logout.
     * @return bool Whether a complete identity was readable. Never persists tokens or changes Chrome.
     */
    public static function pollCodexIdentity(): bool
    {
        $home = (string) Config::get('gateway', 'codex_home');
        if ($home === '') return false;
        $path = rtrim($home, '\\/') . '/auth.json';
        clearstatcache(true, $path);
        if (!is_file($path) || (int) @filesize($path) > 1048576) return false;
        $raw = @file_get_contents($path, false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) return false;
        $auth = json_decode($raw, true);
        if (!is_array($auth)) return false;
        $fingerprint = self::fingerprint($auth);
        unset($auth, $raw);
        if ($fingerprint === null) return false;
        self::observeCodexIdentity($fingerprint);
        return true;
    }

    /** Persist a valid identity transition; initial installation establishes a baseline without a reminder. */
    public static function observeCodexIdentity(string $fingerprint, ?int $now = null): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) return;
        if (self::row()['codex_identity'] === $fingerprint) return;
        $now ??= time();
        Schema::transaction(static function () use ($fingerprint, $now): void {
            $row = self::row();
            if ($row['codex_identity'] === $fingerprint) return;
            self::write(['codex_identity' => $fingerprint, 'updated_at' => $now,
                ...($row['profile_codex_identity'] === null ? ['profile_codex_identity' => $fingerprint] : [])]);
        });
    }

    /** Start the two-day clock on a verified website login, preserving it across polls and gateway restarts. */
    public static function observeWebLogin(string $accountKey, ?int $now = null): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $accountKey) !== 1) return;
        $observed = self::row();
        if ($observed['login_account_key'] === $accountKey && (int) $observed['login_started_at'] > 0) return;
        $now ??= time();
        Schema::transaction(static function () use ($accountKey, $now): void {
            $row = self::row();
            if ($row['login_account_key'] === $accountKey && (int) $row['login_started_at'] > 0) return;
            self::write(['login_account_key' => $accountKey, 'login_started_at' => $now,
                'snoozed_reminder_id' => null, 'snoozed_until' => 0, 'updated_at' => $now]);
        });
    }

    /** A verified logged-out page ends the continuous-login clock without acknowledging a Codex account switch. */
    public static function observeWebLogout(): void
    {
        $observed = self::row();
        if ($observed['login_account_key'] === null && (int) $observed['login_started_at'] === 0) return;
        Schema::transaction(static function (): void {
            $row = self::row();
            if ($row['login_account_key'] === null && (int) $row['login_started_at'] === 0) return;
            self::write(['login_account_key' => null, 'login_started_at' => 0, 'updated_at' => time()]);
        });
    }

    /** Public reminder state: opaque comparison token and timestamps only, never account identifiers or credentials. */
    public static function snapshot(?int $now = null): array
    {
        $now ??= time();
        $row = self::row();
        $reminder = self::reminder($row, $now);
        $snoozed = $reminder !== null && $row['snoozed_reminder_id'] === $reminder['id']
            && (int) $row['snoozed_until'] > $now;
        return ['reason' => $reminder['reason'] ?? null, 'reminder_id' => $reminder['id'] ?? null,
            'show_popup' => $reminder !== null && !$snoozed,
            'login_started_at' => (int) $row['login_started_at'],
            'renew_at' => (int) $row['login_started_at'] > 0 ? (int) $row['login_started_at'] + self::RENEW_AFTER_SECONDS : null,
            'codex_identity_observed' => $row['codex_identity'] !== null];
    }

    /** Snooze only this exact reminder for one day; another account transition creates a different reminder. */
    public static function snooze(string $reminderId, ?int $now = null): void
    {
        $now ??= time();
        Schema::transaction(static function () use ($reminderId, $now): void {
            self::assertReminderUnlocked($reminderId, $now);
            self::write(['snoozed_reminder_id' => $reminderId, 'snoozed_until' => $now + self::SNOOZE_SECONDS,
                'updated_at' => $now]);
        });
    }

    /** Validate a console confirmation inside BrowserState's existing enqueue transaction. @throws GatewayError */
    public static function assertReminderUnlocked(string $reminderId, ?int $now = null): void
    {
        $reminder = self::reminder(self::row(), $now ?? time());
        if ($reminder === null || !hash_equals($reminder['id'], $reminderId)) {
            throw new GatewayError('LOGIN_REMINDER_CHANGED', '登录提醒状态已变化，请重新检查后确认。', 409);
        }
    }

    /** Reset the clock only after the existing worker has successfully purged the owned profile. */
    public static function profileClearedUnlocked(): void
    {
        $row = self::row();
        self::write(['profile_codex_identity' => $row['codex_identity'], 'login_account_key' => null,
            'login_started_at' => 0, 'snoozed_reminder_id' => null, 'snoozed_until' => 0, 'updated_at' => time()]);
    }

    /** Derive the reminder from durable identities; no model text or task status is interpreted. */
    private static function reminder(array $row, int $now): ?array
    {
        $reason = null;
        if ($row['codex_identity'] !== null && $row['profile_codex_identity'] !== null
            && !hash_equals($row['profile_codex_identity'], $row['codex_identity'])) $reason = 'codex_account_changed';
        elseif ((int) $row['login_started_at'] > 0 && $now >= (int) $row['login_started_at'] + self::RENEW_AFTER_SECONDS) $reason = 'login_age';
        if ($reason === null) return null;
        return ['reason' => $reason, 'id' => hash('sha256', $reason . "\0" . $row['codex_identity']
            . "\0" . $row['profile_codex_identity'] . "\0" . $row['login_started_at'])];
    }

    /** Read the singleton through the configured framework connection. @throws GatewayError */
    private static function row(): array
    {
        $row = SDB(null, 'medoo', 'sqlite')->table('gateway_login_lifecycle')->where('id', 1)->find();
        if (!is_array($row)) throw new GatewayError('LOGIN_LIFECYCLE_UNAVAILABLE', '登录提醒状态暂不可读取。', 503);
        return $row;
    }

    /** Publish small lifecycle fields in the caller's short writer transaction. @throws GatewayError */
    private static function write(array $values): void
    {
        $updated = SDB(null, 'medoo', 'sqlite')->table('gateway_login_lifecycle')->where('id', 1)->update($values);
        if ($updated === false) throw new GatewayError('LOGIN_LIFECYCLE_UNAVAILABLE', '登录提醒状态无法保存。', 503);
    }
}
