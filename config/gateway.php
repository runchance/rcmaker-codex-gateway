<?php
/** Local appliance configuration. Editable network settings live in SQLite. */
$consolePort = (int) rcEnv('gateway.console_port', 28910);
$apiPort = (int) rcEnv('gateway.api_port', 28911);
$browserDebugPort = (int) rcEnv('gateway.browser_debug_port', 28912);
$browserHttpProtocol = (string) rcEnv('gateway.browser_http_protocol', 'auto');
if (!in_array($browserHttpProtocol, ['auto', 'http1'], true)) {
    throw new RuntimeException('gateway.browser_http_protocol must be auto or http1.');
}
$databaseName = (string) rcEnv('gateway.database_name', 'gateway.sqlite');
$gatewayTestMode = getenv('PHP_GATEWAY_TEST_MODE') === '1';
$dataName = (string) rcEnv('gateway.data_directory', 'data');
if (!preg_match('/\A\.?[a-zA-Z0-9_-]{1,80}\z/', $dataName)) {
    throw new RuntimeException('gateway.data_directory must name a directory beside the executable.');
}
$dataDirectory = phar_path() . '/' . $dataName;
$defaultLocale = (string) rcEnv('gateway.default_locale', 'en');
if (!in_array($defaultLocale, ['en', 'zh-CN'], true)) throw new RuntimeException('Unsupported gateway default locale.');
$databaseStem = preg_replace('/\.sqlite\z/', '', $databaseName) ?: 'gateway';
$testRuntimeRoot = $dataDirectory . '/tests/tmp/gateway-runtime/' . $databaseStem;
$browserProfileName = $gatewayTestMode ? (string) rcEnv('gateway.browser_profile_name', 'gateway') : 'gateway';
$projectRoot = rtrim(PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', phar_path()) : phar_path(), '/');
$runtimeCandidate = $gatewayTestMode ? (string) rcEnv('gateway.browser_runtime_dir', $dataDirectory . '/browser-runtime') : $dataDirectory . '/browser-runtime';
$runtimeNormalized = rtrim(PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $runtimeCandidate) : $runtimeCandidate, '/');
$runtimeBoundary = PHP_OS_FAMILY === 'Windows' ? strtolower($runtimeNormalized) : $runtimeNormalized;
$projectBoundary = PHP_OS_FAMILY === 'Windows' ? strtolower($projectRoot) : $projectRoot;
$configuredCodexHome = trim((string) rcEnv('gateway.codex_home', ''));
$codexHome = $configuredCodexHome !== '' ? $configuredCodexHome : \support\process\CodexEnvironment::detect();
if ($consolePort < 1024 || $consolePort > 65535 || $apiPort < 1024 || $apiPort > 65535 || $browserDebugPort < 1024 || $browserDebugPort > 65535
    || count(array_unique([$consolePort, $apiPort, $browserDebugPort])) !== 3) {
    throw new RuntimeException('Gateway requires distinct console, API and browser-debug loopback ports between 1024 and 65535.');
}
if (!preg_match('/\A[a-zA-Z0-9_-]+\.sqlite\z/', $databaseName)) {
    throw new RuntimeException('Invalid gateway database filename.');
}
if (!preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $browserProfileName)) {
    throw new RuntimeException('Invalid gateway browser profile name.');
}
if ($runtimeNormalized === '' || str_contains('/' . $runtimeNormalized . '/', '/../')
    || !str_starts_with($runtimeBoundary . '/', $projectBoundary . '/')) {
    throw new RuntimeException('Gateway browser Runtime must stay inside the project directory.');
}
return [
    'data_directory' => $dataDirectory,
    'presentation_file' => $gatewayTestMode ? $testRuntimeRoot . '/presentation.json' : $dataDirectory . '/presentation.json',
    'default_locale' => $defaultLocale,
    // Optional metadata-only release notices. Never downloads updates or restarts a process.
    'update_check_enabled' => filter_var(rcEnv('gateway.update_check_enabled', true), FILTER_VALIDATE_BOOLEAN),
    'default_context_max_parts' => 6,
    'default_context_extended' => true,
    'default_connector_name' => 'RCMAKER Codex',
    // Only the explicit Codex integration reads this external configuration. Runtime data stays private.
    'codex_home' => $codexHome,
    'console_port' => $consolePort,
    'api_port' => $apiPort,
    'browser_debug_port' => $browserDebugPort,
    // Opt-in compatibility for diagnosed HTTP/2 stream errors; never changes an active browser.
    'browser_http_protocol' => $browserHttpProtocol,
    // Upload/indexing may exceed one minute for a full image batch; never extends a task deadline.
    'attachment_upload_timeout_seconds' => max(60, min(1800, (int) rcEnv('gateway.attachment_upload_timeout_seconds', 600))),
    'console_origin' => 'http://127.0.0.1:' . $consolePort,
    'api_origin' => 'http://127.0.0.1:' . $apiPort,
    'database_file' => $dataDirectory . '/database/' . $databaseName,
    // Tests must never share runtime credentials or tunnel-client state with the appliance.
    // A leaked test worker is therefore unable to stop, reconnect or mutate the real tunnel.
    'runtime_secret_key_file' => $gatewayTestMode
        ? $testRuntimeRoot . '/secrets/runtime-secretbox.key'
        : $dataDirectory . '/secrets/runtime-secretbox.key',
    'tunnel_runtime_root' => $gatewayTestMode
        ? $testRuntimeRoot . '/tunnel'
        : $dataDirectory . '/runtime/tunnel',
    // Step 08 content-addressed input/artifact storage. Files are project-private and tests never
    // share them with the appliance runtime.
    'attachment_store_dir' => $gatewayTestMode
        ? $testRuntimeRoot . '/attachments'
        : $dataDirectory . '/attachments',
    'artifact_download_dir' => $gatewayTestMode
        ? $testRuntimeRoot . '/artifact-downloads'
        : $dataDirectory . '/browser-temp/artifact-downloads',
    // Browser state is intentionally split from legacy/system browser state. Never point these
    // directories at an OS browser profile or an external cache/temp location.
    'browser_profile_dir' => $dataDirectory . '/browser-profile/cft/' . $browserProfileName,
    'browser_cache_dir' => $dataDirectory . '/browser-cache/cft/' . $browserProfileName,
    'browser_temp_dir' => $dataDirectory . '/browser-temp/cft/' . $browserProfileName,
    'browser_home_dir' => $dataDirectory . '/browser-home/cft/' . $browserProfileName,
    'browser_log_dir' => $dataDirectory . '/browser-log/cft/' . $browserProfileName,
    'browser_runtime_dir' => $runtimeNormalized,
    'browser_runtime_manifest_url' => 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json',
    'browser_runtime_lock_timeout_seconds' => 120,
    'browser_runtime_publish_timeout_seconds' => max(1, min(30, (int) rcEnv('gateway.browser_runtime_publish_timeout_seconds', 10))),
    'browser_start_timeout_seconds' => max(30, min(600, (int) rcEnv('gateway.browser_start_timeout_seconds', 180))),
    // Deployment-owned allowlist: the HTTP API accepts a target ID, never a URL.
    'probe_targets' => [
        'model_site' => ['label' => '模型网站', 'url' => (string) rcEnv('gateway.diagnostic_url', 'https://chatgpt.com/')],
    ],
    'probe_ca_file' => (string) rcEnv('gateway.probe_ca_file', ''),
    'probe_timeout_seconds' => 8,
    'probe_queue_timeout_seconds' => 20,
    'max_revisions' => 1000,
];
