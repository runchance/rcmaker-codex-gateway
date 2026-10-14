<?php
declare(strict_types=1);
namespace support\browser;

/** Bounded passive CDP metrics for one owned page document; never stores bodies or changes requests. */
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
     * @return array|null Bounded timeline facts, without URL, headers, payload or conversation ID.
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
            return null;
        }
        if ($method === 'Network.dataReceived') {
            foreach (['dataLength' => 'received_bytes', 'encodedDataLength' => 'encoded_bytes'] as $from => $to) {
                $row[$to] = min(2147483647, $row[$to] + max(0, (int) ($params[$from] ?? 0)));
            }
            $row['data_events'] = min(2147483647, $row['data_events'] + 1);
            $row['last_data'] = $now;
            return null;
        }
        if (!in_array($method, ['Network.loadingFinished', 'Network.loadingFailed'], true)) return null;
        $failed = $method === 'Network.loadingFailed';
        $facts = array_intersect_key($row, array_flip(['endpoint','request_bytes','received_bytes',
            'encoded_bytes','data_events','http_status','protocol','connection_reused']));
        if (!$failed && is_numeric($params['encodedDataLength'] ?? null)) {
            $facts['encoded_bytes'] = min(2147483647, max(0, (int) $params['encodedDataLength']));
        }
        $facts['elapsed_ms'] = (int) round(max(0, $now - $row['started']) * 1000);
        $facts['last_data_age_ms'] = $row['last_data'] === null ? null : (int) round(max(0, $now - $row['last_data']) * 1000);
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
}
