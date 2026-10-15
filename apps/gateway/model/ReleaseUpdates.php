<?php
declare(strict_types=1);
namespace app\gateway\model;

use RC\Config;

/** Optional release notices. Only the diagnostic worker performs HTTP; the API reads cached metadata. */
final class ReleaseUpdates
{
    private const ENDPOINT = 'https://api.github.com/repos/runchance/rcmaker-codex-gateway/releases/latest';
    private const RELEASES = 'https://github.com/runchance/rcmaker-codex-gateway/releases/tag/';
    private const INTERVAL = 21600;
    private const RETRY = 3600;
    private const CACHE_TTL = 604800;
    private int $nextPoll;

    /** @param int|null $startedAt Worker start time; defer optional traffic until startup has settled. */
    public function __construct(?int $startedAt = null)
    {
        $this->nextPoll = ($startedAt ?? time()) + 60;
    }

    /**
     * Check at most every six hours, using only an explicitly applied network configuration.
     * Called by the single diagnostic owner, outside any database transaction. Failures are silent.
     * @param int|null $now Current Unix time, optionally supplied by an isolated scheduler test.
     * @return array Framework Banner lines, emitted once per new version per cached installation.
     */
    public function tick(?int $now = null): array
    {
        $now ??= time();
        if ($now < $this->nextPoll) return [];
        $this->nextPoll = $now + 60;
        if (!self::enabled() || !self::validTag(self::currentVersion())) return [];
        try {
            $state = self::readCache();
            if ($now >= (int) ($state['next_check_at'] ?? 0)) {
                // Operator-requested diagnostics take priority over optional update checks.
                $pending = SDB(null, 'medoo', 'sqlite')->table('gateway_network_tests')
                    ->where('status', 'IN', ['queued', 'running'])->count();
                if ($pending === false || $pending === null || $pending > 0) return [];
                $network = NetworkSettings::state();
                $revisionId = (int) ($network['applied_php_revision'] ?? 0);
                if ($revisionId < 1 || $revisionId !== (int) $network['saved_revision']) return [];
                $revision = NetworkSettings::revision($revisionId);
                NetworkSettings::usable($revision);
                // The in-memory deadline also protects against a failed cache write.
                $this->nextPoll = $now + self::RETRY;
                $state['next_check_at'] = $this->nextPoll;
                try {
                    $state['latest_version'] = self::fetchLatest($revision);
                    $state['checked_at'] = $now;
                    $state['next_check_at'] = $now + self::INTERVAL;
                } catch (\Throwable) {
                    // Keep a previously verified notice; never log response bodies, proxy data or credentials.
                }
                self::writeCache($state);
            }
            $this->nextPoll = max($now + 60, (int) ($state['next_check_at'] ?? 0));
            $snapshot = self::project($state, $now);
            if (!$snapshot['available'] || ($state['announced_version'] ?? '') === $snapshot['latest_version']) return [];
            $state['announced_version'] = $snapshot['latest_version'];
            self::writeCache($state);
            $zh = GatewayPresentation::locale() === 'zh-CN';
            return [
                ['text' => ($zh ? 'RCMAKER 有新版本：' : 'RCMAKER update available: ')
                    . $snapshot['current_version'] . ' → ' . $snapshot['latest_version'], 'color' => 'bright_cyan'],
                ['text' => ($zh ? '空闲时查看更新：' : 'View when convenient: ') . $snapshot['release_url'], 'color' => 'gray'],
            ];
        } catch (\Throwable) {
            // Optional notices must never fail readiness, interrupt tasks or create a retry loop.
            $this->nextPoll = max($this->nextPoll, $now + 300);
            return [];
        }
    }

    /** @return array Safe cached metadata only; no HTTP, account lookup, browser work or state mutation. */
    public static function snapshot(): array
    {
        try {
            return self::project(self::enabled() ? self::readCache() : [], time());
        } catch (\Throwable) {
            return self::project([], time());
        }
    }

    /** @return bool Whether deployment configuration permits background release checks. */
    private static function enabled(): bool
    {
        return (bool) Config::get('gateway', 'update_check_enabled');
    }

    /** @return string Build-injected tag; source trees with an unknown dev version are not compared. */
    private static function currentVersion(): string
    {
        return (string) Config::get('release', 'tag');
    }

    /** Accept only stable release tags supported by the installer; reject arbitrary labels and URLs. */
    private static function validTag(string $tag): bool
    {
        return preg_match('/\Av(?:0|[1-9][0-9]{0,7})\.(?:0|[1-9][0-9]{0,7})\.(?:0|[1-9][0-9]{0,7})\z/', $tag) === 1;
    }

    /** @return string Cache isolation follows the configured data location and installed release. */
    private static function cacheKey(): string
    {
        return 'gateway_release_v1_' . hash('sha256', Config::get('gateway', 'database_file') . '|' . self::currentVersion());
    }

    /** @return array Disposable release metadata, never task history or credentials. */
    private static function readCache(): array
    {
        $state = cache()->get(self::cacheKey());
        return is_array($state) ? $state : [];
    }

    /** Persist the bounded check deadline and notice deduplication through the configured cache driver. */
    private static function writeCache(array $state): void
    {
        if (!cache()->set(self::cacheKey(), $state, self::CACHE_TTL)) {
            throw new \RuntimeException('Release notice cache is unavailable.');
        }
    }

    /** Project verified, recent stable versions into a small console response. */
    private static function project(array $state, int $now): array
    {
        $current = self::currentVersion();
        $latest = is_string($state['latest_version'] ?? null) ? $state['latest_version'] : '';
        $checkedAt = (int) ($state['checked_at'] ?? 0);
        $verified = self::enabled() && self::validTag($current) && self::validTag($latest)
            && $checkedAt > $now - self::CACHE_TTL && $checkedAt <= $now;
        $available = $verified && version_compare(substr($latest, 1), substr($current, 1), '>');
        return [
            'enabled' => self::enabled(), 'current_version' => $current,
            'latest_version' => $verified ? $latest : null, 'available' => $available,
            'release_url' => $available ? self::RELEASES . $latest : null,
            'checked_at' => $verified ? $checkedAt : null,
        ];
    }

    /**
     * Fetch public stable-release metadata through the existing TLS/proxy policy, with bounded time/body size.
     * @param array $revision Applied immutable network revision; never sent in the HTTP body.
     * @return string Validated stable tag. @throws \Throwable On transport, HTTP or metadata failure.
     */
    private static function fetchLatest(#[\SensitiveParameter] array $revision): string
    {
        $client = curl();
        $body = '';
        try {
            NetworkTransport::configure($client, $revision, self::ENDPOINT, 4, 2, false);
            $client->setUserAgent('RCMAKER-Codex-Gateway/' . self::currentVersion());
            $client->setHeader('Accept', 'application/vnd.github+json');
            $client->setHeader('X-GitHub-Api-Version', '2026-03-10');
            $client->setJsonDecoder(false);
            $client->setDefaultDecoder(false);
            $client->setOpt(CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) return 0;
                $body .= $chunk;
                return strlen($chunk);
            });
            $client->get(self::ENDPOINT);
            if ($client->curlErrorCode !== 0 || (int) $client->httpStatusCode !== 200) {
                throw new \RuntimeException('Release metadata could not be verified.');
            }
            $release = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($release) || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false) {
                throw new \RuntimeException('No stable release metadata.');
            }
            $fields = validator()->input($release, [
                'tag_name' => ['rule' => 'string', 'len' => [6, 28], 'name' => 'Release tag'],
            ]);
            if (!self::validTag($fields['tag_name'])) throw new \RuntimeException('Invalid stable release tag.');
            return $fields['tag_name'];
        } finally {
            $client->close();
        }
    }
}
