<?php
declare(strict_types=1);
// This process owns an MCP JSON-RPC stdout stream; diagnostics must stay on stderr.
ini_set('display_errors', 'stderr');

/**
 * Project-private MCP stdio child launched by tunnel-client.
 * It shares only this RCMAKER application's SQLite database and emits JSON-RPC on stdout.
 */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 3));
define('IS_SCRIPT', true);
require ROOT_PATH . '/vendor/autoload.php';

Workerman\Timer::init(new Workerman\Events\Select());

use app\gateway\database\Schema;
use app\gateway\model\McpProtocol;
use app\gateway\model\ToolBroker;

/**
 * Write a bounded, non-secret MCP handshake trace for live diagnostics.
 * Never record request_id/turn tokens, tool arguments, API keys, or response bodies.
 */
function gatewayMcpTrace(string $event, array $detail = []): void
{
    try {
        $root = rtrim(str_replace('\\', '/', (string) RC\Config::get('gateway', 'tunnel_runtime_root')), '/');
        if ($root === '') return;
        $dir = $root . '/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
        $path = $dir . '/mcp-events.jsonl';
        clearstatcache(true, $path);
        if (is_file($path) && (int) @filesize($path) > 1024 * 1024) {
            @unlink($path . '.1');
            @rename($path, $path . '.1');
        }
        $safe = [];
        foreach ($detail as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[a-z0-9_]{1,40}\z/i', $key)) continue;
            if (is_bool($value) || is_int($value) || $value === null) $safe[$key] = $value;
            elseif (is_string($value)) $safe[$key] = mb_substr($value, 0, 160);
        }
        $row = ['at' => time(), 'pid' => getmypid(), 'event' => $event] + $safe;
        @file_put_contents(
            $path,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable) {
        // Diagnostics must never interfere with the stdio protocol.
    }
}

/**
 * Correlate a tools/call with local ledger IDs only; never persist its token or arguments.
 * @param array<string,mixed> $request Parsed JSON-RPC request.
 * @return array<string,mixed> Non-secret format/fingerprint facts and exact binding state when found.
 */
function gatewayMcpBindingTrace(array $request): array
{
    if (($request['method'] ?? null) !== 'tools/call') return [];
    $token = $request['params']['arguments']['turn_token'] ?? $request['params']['arguments']['request_id'] ?? null;
    if (!is_string($token)) return [];
    $validFormat = preg_match('/\A(?:turn_[A-Za-z0-9_-]{32}|request_[A-Za-z0-9_-]{24,128})\z/', $token) === 1;
    $identity = ['capability_format_valid' => $validFormat];
    if (!$validFormat) {
        // Keep these scalar so gatewayMcpTrace() persists the shape; never log
        // the capability itself, even when the Web model supplied a malformed one.
        $identity['capability_length'] = strlen($token);
        $identity['capability_prefix'] = str_starts_with($token, 'turn_') ? 'turn'
            : (str_starts_with($token, 'request_') ? 'request' : 'other');
        $identity['capability_ascii_only'] = preg_match('/\A[A-Za-z0-9_-]*\z/', $token) === 1;
        return $identity;
    }
    // A short one-way fingerprint correlates unmatched calls without retaining capability text.
    $identity['capability_fingerprint'] = substr(hash('sha256', $token), 0, 12);
    try {
        $binding = SDB(null, 'medoo', 'sqlite')->table('gateway_mcp_bindings')->where('token_hash', hash('sha256', $token))->find();
        if (!is_array($binding)) return $identity;
        return $identity + ['binding_id' => (string) $binding['id'], 'response_id' => (string) $binding['current_response_id'],
            'binding_status' => (string) $binding['status'], 'sent' => $binding['sent_at'] !== null,
            'started' => $binding['started_at'] !== null];
    } catch (Throwable) {
        return $identity;
    }
}

/**
 * Initialize either stdio process, tolerating bounded initialization/SQLite lock contention.
 * No tool has been dispatched at this point; retrying initialization cannot replay a host action.
 * STORAGE_BUSY means the operation never entered its transaction body. Schema validation,
 * other SQL failures, permissions and failures after a write remain final.
 */
function gatewayMcpBootstrap(): void
{
    foreach ((array) RC\Config::get('process')['RC_GATEWAY_API']['bootstrap'] as $bootstrap) {
        if ($bootstrap !== app\gateway\bootstrap\Initialize::class) $bootstrap::start();
    }
    for ($attempt = 1; ; $attempt++) {
        try {
            Schema::initialize();
            return;
        } catch (app\gateway\model\GatewayError $error) {
            if ($error->errorCode !== 'STORAGE_BUSY' || $attempt >= 3) throw $error;
            gatewayMcpTrace('bootstrap_wait', ['attempt' => $attempt, 'error_code' => $error->errorCode]);
            usleep(50000);
        }
    }
}

/**
 * Serialize a complete JSON-RPC frame across the parent and concurrent Windows children.
 * The durable host-result receipt is recorded only after the whole frame reaches stdout.
 *
 * @param array<string,mixed> $response JSON-RPC response.
 * @param array<string,mixed>|null $request Original request for non-secret tracing.
 * @param array{token:string,call_id:string,settled?:bool}|null $receipt Internal host-call identity.
 */
function gatewayMcpEmit(array $response, ?array $request, int $sequence, float $started,
    string $lockPath, ?array $receipt = null): void
{
    $lockStarted = microtime(true);
    $lock = @fopen($lockPath, 'c+b');
    if (!is_resource($lock) || !flock($lock, LOCK_EX)) throw new RuntimeException('MCP output lock unavailable');
    $lockWaitMs = (int) round((microtime(true) - $lockStarted) * 1000);
    try {
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $length = strlen($encoded);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite(STDOUT, substr($encoded, $offset, 65536));
            if (!is_int($written) || $written < 1) break;
            $offset += $written;
        }
        $flushed = $offset === $length && fflush(STDOUT);
        $method = is_string($request['method'] ?? null) ? $request['method'] : null;
        $toolName = $method === 'tools/call' && is_string($request['params']['name'] ?? null)
            ? $request['params']['name'] : null;
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $structured = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : [];
        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
        $toolError = $structured['code'] ?? null;
        $catalogNames = [];
        if ($method === 'tools/list' && is_array($result['tools'] ?? null)) {
            foreach ($result['tools'] as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
                    $catalogNames[] = $tool['name'];
                }
            }
        }
        $receiptFailed = false;
        if ($flushed && $receipt !== null && $error === []
            && (!empty($receipt['settled']) || empty($result['isError']))) {
            try {
                ToolBroker::markResultDelivered($receipt['token'], $receipt['call_id']);
            } catch (Throwable) {
                $receiptFailed = true;
                // A delivered result must never be resent just because bookkeeping failed.
            }
        }
        gatewayMcpTrace('response', [
            'method' => $method, 'tool' => $toolName, 'sequence' => $sequence,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'output_bytes' => $length, 'lock_wait_ms' => $lockWaitMs,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'flushed' => $flushed,
            'error_code' => is_int($error['code'] ?? null) ? $error['code'] : null,
            'is_error' => !empty($result['isError']),
            'tool_error_code' => !empty($result['isError']) && is_string($toolError) ? $toolError : null,
            // The App may cache an earlier public tool list. Keep names only, never schemas
            // or arguments, so a fresh ChatGPT tools/list handshake can be verified.
            'tool_count' => $method === 'tools/list' ? count($catalogNames) : null,
            'tool_names' => $method === 'tools/list' ? implode(',', $catalogNames) : null,
        ] + (is_array($request) ? gatewayMcpBindingTrace($request) : []));
        if ($receiptFailed) gatewayMcpTrace('receipt_error', ['sequence' => $sequence]);
        if (!$flushed) throw new RuntimeException('MCP stdout frame incomplete');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Run one potentially blocking tool call in its own PHP process; return an exit status. */
function gatewayMcpToolWorker(string $lockPath, int $sequence, float $started, ?string $sessionScope): int
{
    $request = null;
    $phase = 'input';
    $inputBytes = 0;
    $emitStarted = false;
    try {
        // Consume the complete pipe before initialization can wait on another process's lock.
        // Otherwise a child that expires during bootstrap closes stdin while its parent is
        // still writing a large request; that request receives no JSON-RPC response at all.
        $line = fgets(STDIN, 10 * 1024 * 1024 + 2);
        if (!is_string($line)) return 1;
        $inputBytes = strlen($line);
        if (!str_ends_with($line, "\n")) throw new UnexpectedValueException('Incomplete MCP input frame');
        $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new UnexpectedValueException('Invalid MCP request');
        $request = $decoded;
        $phase = 'bootstrap';
        // An already-running parent may still launch workers with the older four-argument
        // command until its normal reload. Keep those in-flight connections usable.
        McpProtocol::setSessionScope($sessionScope === '' ? null : $sessionScope);
        gatewayMcpBootstrap();
        $phase = 'handle';
        $response = McpProtocol::handle($request);
        $receipt = McpProtocol::takeHostReceipt();
        if (is_array($response)) {
            $phase = 'emit';
            $emitStarted = true;
            gatewayMcpEmit($response, $request, $sequence, $started, $lockPath, $receipt);
        }
        return 0;
    } catch (Throwable $error) {
        $errorCode = $error instanceof app\gateway\model\GatewayError ? $error->errorCode : null;
        gatewayMcpTrace('worker_error', ['sequence' => $sequence, 'phase' => $phase,
            'exception_class' => get_class($error), 'error_code' => $errorCode, 'input_bytes' => $inputBytes]);
        // Report once when the request is known and no stdout emission has begun. A partial
        // stdout frame must never be followed by another frame or claimed as a native receipt.
        if (!$emitStarted && is_array($request) && array_key_exists('id', $request)) {
            $notDispatched = $phase === 'bootstrap';
            $code = $notDispatched ? ($errorCode ?? 'MCP_WORKER_INITIALIZATION_FAILED') : 'MCP_WORKER_OUTCOME_UNCONFIRMED';
            $message = $notDispatched
                ? 'The local MCP worker could not initialize. This request was not dispatched to Codex; retry after the local tool connection recovers.'
                : 'The local MCP worker failed before returning a complete result. The execution outcome is unconfirmed; check the existing call in the task timeline and do not replay the action.';
            $failure = ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => [
                'content' => [['type' => 'text', 'text' => $message]],
                'structuredContent' => ['ok' => false, 'code' => $code,
                    'execution_status' => $notDispatched ? 'not_dispatched' : 'unconfirmed', 'message' => $message],
                'isError' => true,
            ]];
            try {
                gatewayMcpEmit($failure, $request, $sequence, $started, $lockPath);
            } catch (Throwable $emitError) {
                gatewayMcpTrace('worker_failure_emit_error', ['sequence' => $sequence,
                    'exception_class' => get_class($emitError)]);
            }
        }
        // The host may already have executed a call; never replay it here.
        return 1;
    }
}

/** Reap completed tool children without blocking the incoming MCP request reader. */
function gatewayMcpReap(array &$children): void
{
    foreach ($children as $key => $child) {
        $status = proc_get_status($child);
        if ($status === false || !empty($status['running'])) continue;
        $code = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : null;
        proc_close($child);
        gatewayMcpTrace('worker_exit', ['sequence' => $key, 'exit_code' => $code]);
        unset($children[$key]);
    }
}

/** Complete an oversized input frame before reading the next JSON-RPC request. */
function gatewayMcpDrainOversizedLine(): void
{
    while (!feof(STDIN)) {
        $chunk = fgets(STDIN, 65536);
        if ($chunk === false || str_ends_with($chunk, "\n")) break;
    }
}

if (($argv[1] ?? '') === '--tool-worker') {
    exit(gatewayMcpToolWorker((string) ($argv[2] ?? ''), (int) ($argv[3] ?? 0),
        (float) ($argv[4] ?? 0), isset($argv[5]) ? (string) $argv[5] : null));
}

gatewayMcpBootstrap();
gatewayMcpTrace('process_start');
$lockPath = tempnam(sys_get_temp_dir(), 'rcmaker-mcp-stdout-');
if (!is_string($lockPath)) throw new RuntimeException('Cannot reserve MCP output lock');
@chmod($lockPath, 0600);
$maxLine = 10 * 1024 * 1024;
$maxChildren = 8;
$sessionScope = bin2hex(random_bytes(16));
$requestSequence = 0;
$children = [];
$protocolInitialized = false;
$catalogNotificationSent = false;
try {
    while (!feof(STDIN)) {
        gatewayMcpReap($children);
        $line = fgets(STDIN, $maxLine + 2);
        if ($line === false) break;
        $requestSequence++;
        $requestStarted = microtime(true);
        if (strlen($line) > $maxLine) {
            if (!str_ends_with($line, "\n")) gatewayMcpDrainOversizedLine();
            gatewayMcpEmit(['jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32600, 'message' => 'Request too large']],
                null, $requestSequence, $requestStarted, $lockPath);
            continue;
        }
        $line = trim($line);
        if ($line === '') continue;
        try {
            $request = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            gatewayMcpTrace('parse_error');
            gatewayMcpEmit(['jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error']],
                null, $requestSequence, $requestStarted, $lockPath);
            continue;
        }
        $request = is_array($request) ? $request : null;
        $method = is_string($request['method'] ?? null) ? $request['method'] : null;
        $toolName = $method === 'tools/call' && is_string($request['params']['name'] ?? null)
            ? $request['params']['name'] : null;
        if ($request !== null) {
            $trace = ['method' => $method, 'tool' => $toolName, 'sequence' => $requestSequence]
                + gatewayMcpBindingTrace($request);
            if ($method === 'initialize' && is_string($request['params']['protocolVersion'] ?? null)) {
                $trace['protocol_version'] = $request['params']['protocolVersion'];
            }
            gatewayMcpTrace('request', $trace + ['input_bytes' => strlen($line)]);
        }
        if ($method === 'notifications/cancelled') {
            // An already-delivered host operation may have an unknown side effect. Do not kill
            // its worker or silently replay it merely because the MCP caller stopped waiting.
            gatewayMcpTrace('cancellation_observed', ['active_workers' => count($children)]);
        }
        if ($method === 'tools/call' && array_key_exists('id', $request ?? [])) {
            while (count($children) >= $maxChildren) {
                gatewayMcpReap($children);
                if (count($children) >= $maxChildren) usleep(20000);
            }
            $child = proc_open(\support\process\GatewayEntrypoint::command('--gateway-mcp', ['--tool-worker', $lockPath,
                    (string) $requestSequence, (string) $requestStarted, $sessionScope]),
                [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, phar_path(), getenv(),
                ['bypass_shell' => true, 'create_no_window' => true]);
            if (!is_resource($child)) {
                gatewayMcpEmit(['jsonrpc' => '2.0', 'id' => $request['id'],
                    'error' => ['code' => -32603, 'message' => 'Tool worker unavailable']],
                    $request, $requestSequence, $requestStarted, $lockPath);
                continue;
            }
            $payload = $line . "\n";
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = @fwrite($pipes[0], substr($payload, $offset, 65536));
                if (!is_int($written) || $written < 1) break;
                $offset += $written;
            }
            fclose($pipes[0]);
            $children[$requestSequence] = $child;
            if ($offset !== strlen($payload)) {
                gatewayMcpTrace('worker_input_incomplete', ['sequence' => $requestSequence]);
                // The worker may have started a host call; do not send the request twice.
            }
            continue;
        }
        try {
            $response = $request !== null
                ? McpProtocol::handle($request)
                : ['jsonrpc' => '2.0', 'id' => null,
                    'error' => ['code' => -32600, 'message' => 'Invalid Request']];
        } catch (Throwable) {
            $response = ['jsonrpc' => '2.0', 'id' => null,
                'error' => ['code' => -32603, 'message' => 'Internal error']];
        }
        if ($response === null) {
            if ($protocolInitialized && !$catalogNotificationSent
                && $method === 'notifications/initialized' && ($request['jsonrpc'] ?? null) === '2.0'
                && !array_key_exists('id', $request ?? [])) {
                // Reconnection may otherwise retain an older App tool snapshot. Notify once,
                // after the handshake, without changing any active host-call lifecycle.
                gatewayMcpEmit(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed'],
                    null, $requestSequence, $requestStarted, $lockPath);
                $catalogNotificationSent = true;
                gatewayMcpTrace('catalog_refresh_notified');
            }
            gatewayMcpTrace('notification_complete', ['method' => $method]);
            continue;
        }
        gatewayMcpEmit($response, $request, $requestSequence, $requestStarted, $lockPath);
        if ($method === 'initialize' && isset($response['result']['protocolVersion'])) {
            $protocolInitialized = true;
        }
    }
} finally {
    foreach ($children as $sequence => $child) {
        $code = proc_close($child);
        gatewayMcpTrace('worker_exit', ['sequence' => $sequence, 'exit_code' => $code]);
    }
    @unlink($lockPath);
    gatewayMcpTrace('process_end');
}
