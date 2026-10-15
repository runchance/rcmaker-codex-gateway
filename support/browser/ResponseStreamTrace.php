<?php
declare(strict_types=1);
namespace support\browser;

/** Bounded passive CDP metrics for one owned document; no bodies, raw headers or request changes. */
final class ResponseStreamTrace
{
    private array $requests = [];
    private array $lastRecorded = [];
    private ?string $conversation = null;

    /**
     * Observe already occurring network events. Background reads require the exact conversation
     * ID from this document's submission; absent identity stays unknown, never inferred from a URL.
     * @param string $method CDP event name.
     * @param array $params Transient CDP parameters, discarded after numeric metrics are extracted.
     * @param string $frame Owned main frame ID.
     * @param string $modelUrl Configured model origin.
     * @param float $now Observer clock in seconds; negative elapsed differences are clamped.
     * @return array|null Timeline metrics and validated server correlation IDs; no raw headers or payload.
     */
    public function observe(string $method, array $params, string $frame, string $modelUrl, float $now): ?array
    {
        $id = $params['requestId'] ?? null;
        if (!is_string($id) || $id === '') return null;
        if ($method === 'Network.requestWillBeSent') {
            unset($this->requests[$id]); // Redirects must be revalidated, including their origin.
            if ($frame === '' || ($params['frameId'] ?? '') !== $frame) return null;
            $request = (array) ($params['request'] ?? []);
            $url = (string) ($request['url'] ?? '');
            if (!ChatGptPage::isBackendUrl($url, $modelUrl)) return null;
            $kind = null;
            $post = $request['postData'] ?? null;
            if (ChatGptPage::isConversationSubmission($params, $frame, $modelUrl)) {
                $kind = 'submission';
                $body = is_string($post) && strlen($post) <= 4194304 ? json_decode($post, true, 64) : null;
                $conversation = $body['conversation_id'] ?? null;
                $this->conversation = is_string($conversation)
                    && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $conversation)
                    ? hash('sha256', strtolower($conversation)) : null;
            } elseif (($request['method'] ?? '') === 'GET' && $this->conversation !== null
                && preg_match('~\A/backend-api/conversation/([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})(?:/(stream_status|stream))?\z~iD',
                    (string) parse_url($url, PHP_URL_PATH), $match)
                && hash_equals($this->conversation, hash('sha256', strtolower($match[1])))) {
                $kind = match ($match[2] ?? '') {
                    'stream_status' => 'stream_status', 'stream' => 'resume', default => 'snapshot',
                };
            }
            if ($kind === null) return null;
            // Evict only diagnostic state; task/transport ownership is kept elsewhere.
            if (!isset($this->requests[$id]) && count($this->requests) >= 32) array_shift($this->requests);
            $this->requests[$id] = ['endpoint' => $kind, 'conversation' => $this->conversation, 'started' => $now, 'last_data' => null,
                'browser_started' => self::browserTimestamp($params), 'browser_last_data' => null,
                'request_bytes' => is_string($post) ? strlen($post) : null,
                'received_bytes' => 0, 'encoded_bytes' => 0, 'data_events' => 0];
            return null;
        }
        if (!isset($this->requests[$id])) return null;
        $row = &$this->requests[$id];
        if ($row['endpoint'] !== 'submission' && $row['conversation'] !== $this->conversation) {
            unset($this->requests[$id]);
            return null;
        }
        if ($method === 'Network.responseReceived') {
            $response = (array) ($params['response'] ?? []);
            $row['http_status'] = (int) ($response['status'] ?? 0);
            $row['protocol'] = $response['protocol'] ?? null;
            $row['connection_reused'] = is_bool($response['connectionReused'] ?? null) ? $response['connectionReused'] : null;
            $row += self::responseDiagnostics($response);
            $headerTime = self::browserDuration($row['browser_started'], self::browserTimestamp($params));
            if ($headerTime !== null) $row['response_headers_ms'] = $headerTime;
            return null;
        }
        if ($method === 'Network.dataReceived') {
            foreach (['dataLength' => 'received_bytes', 'encodedDataLength' => 'encoded_bytes'] as $from => $to) {
                $row[$to] = min(2147483647, $row[$to] + max(0, (int) ($params[$from] ?? 0)));
            }
            $row['data_events'] = min(2147483647, $row['data_events'] + 1);
            $row['last_data'] = $now;
            $row['browser_last_data'] = self::browserTimestamp($params);
            return null;
        }
        if (!in_array($method, ['Network.loadingFinished', 'Network.loadingFailed'], true)) return null;
        $failed = $method === 'Network.loadingFailed';
        $facts = array_intersect_key($row, array_flip(['endpoint','request_bytes','received_bytes',
            'encoded_bytes','data_events','http_status','protocol','connection_reused',
            'response_headers_ms','retry_after_seconds','edge_trace_id','upstream_request_id',
            'from_service_worker','from_disk_cache']));
        if (!$failed && is_numeric($params['encodedDataLength'] ?? null)) {
            $facts['encoded_bytes'] = min(2147483647, max(0, (int) $params['encodedDataLength']));
        }
        $facts['elapsed_ms'] = (int) round(max(0, $now - $row['started']) * 1000);
        $facts['last_data_age_ms'] = $row['last_data'] === null ? null : (int) round(max(0, $now - $row['last_data']) * 1000);
        // Chromium timestamps share a monotonic clock. Keep them separate from worker wall
        // time so delayed CDP delivery or a host clock adjustment cannot look like a network limit.
        $browserEnd = self::browserTimestamp($params);
        $browserElapsed = self::browserDuration($row['browser_started'], $browserEnd);
        $browserDataAge = self::browserDuration($row['browser_last_data'], $browserEnd);
        if ($browserElapsed !== null) $facts['browser_elapsed_ms'] = $browserElapsed;
        if ($browserDataAge !== null) $facts['browser_last_data_age_ms'] = $browserDataAge;
        $facts['request_finished'] = !$failed;
        $facts['request_fingerprint'] = hash('sha256', $id);
        if ($failed) {
            $facts['error_code'] = $params['errorText'] ?? null;
            $facts['canceled'] = is_bool($params['canceled'] ?? null) ? $params['canceled'] : null;
        }
        unset($this->requests[$id]);
        $state = $facts['endpoint'] === 'submission' ? 'stream_metrics' : ($failed ? 'background_failed' : 'background_finished');
        // A polling endpoint can run every second. Persist transitions and at most one repeated
        // outcome per minute, with one bounded slot per endpoint and an error/state signature.
        if ($facts['endpoint'] !== 'submission') {
            $key = $facts['endpoint'];
            $signature = hash('sha256', json_encode([$failed, $facts['http_status'] ?? null, $facts['protocol'] ?? null,
                $facts['error_code'] ?? null, $facts['canceled'] ?? null]));
            $last = $this->lastRecorded[$key] ?? [];
            if (($last['signature'] ?? '') === $signature && $now - ($last['at'] ?? 0) < 60) return null;
            $this->lastRecorded[$key] = ['signature' => $signature, 'at' => $now];
        }
        return ['state' => $state, 'metadata' => $facts];
    }

    /** Accept only finite CDP numeric monotonic timestamps; absence must remain unknown. */
    private static function browserTimestamp(array $params): ?float
    {
        $value = $params['timestamp'] ?? null;
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0
            ? (float) $value : null;
    }

    /** Convert an ordered same-clock interval to bounded diagnostic milliseconds, never a deadline. */
    private static function browserDuration(?float $start, ?float $end): ?int
    {
        if ($start === null || $end === null || $end < $start) return null;
        return (int) round(min(2147483.647, $end - $start) * 1000);
    }

    /**
     * Extract fixed-format correlation IDs and numeric Retry-After evidence from an observed
     * response. Unknown headers, cookies, free text and HTTP-date retry values are discarded.
     * These fields do not cause requests, sleeps or retries.
     */
    private static function responseDiagnostics(array $response): array
    {
        $facts = [];
        foreach (['fromServiceWorker' => 'from_service_worker', 'fromDiskCache' => 'from_disk_cache'] as $key => $field) {
            if (is_bool($response[$key] ?? null)) $facts[$field] = $response[$key];
        }
        if (!is_array($response['headers'] ?? null)) return $facts;
        foreach ($response['headers'] as $key => $value) {
            if (!is_string($key) || !is_string($value) || strlen($value) > 64) continue;
            $key = strtolower($key);
            if ($key === 'cf-ray' && preg_match('/\A[a-f0-9]{16,32}-[A-Z]{3}\z/D', $value)) {
                $facts['edge_trace_id'] = $value;
            } elseif ($key === 'x-request-id'
                && preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/iD', $value)) {
                $facts['upstream_request_id'] = $value;
            } elseif ($key === 'retry-after' && preg_match('/\A[0-9]{1,7}\z/D', $value)) {
                $facts['retry_after_seconds'] = (int) $value;
            }
        }
        return $facts;
    }
}
