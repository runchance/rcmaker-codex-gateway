<?php
/** Separate loopback APP groups plus isolated network, maintenance and browser workers. */
$gateway = RC\Config::get('gateway');
\support\process\GatewayLaunch::acquire();
// The two backends share implementation and limits, but never an event loop.
$apiProcess = [
    'type' => 'app',
    'count' => 1,
    'reusePort' => false,
    'memory_limit' => '512M',
    'package_max_length' => app\gateway\model\ResponsesProtocol::MAX_BODY_BYTES + 16384 + 4,
    // Inherit max_request from the configured framework engine.
    'bootstrap' => [app\gateway\bootstrap\HttpLimits::class, RC\Helper\Db\Medoo::class, RC\Helper\Cache\Raw::class, app\gateway\bootstrap\Initialize::class],
];
return [
    'RC_GATEWAY_CONSOLE' => [
        'type' => 'app',
        'listen' => 'http://127.0.0.1:' . $gateway['console_port'],
        'count' => 1,
        'default_app' => 'console',
        'reusePort' => false,
        'memory_limit' => '128M',
        // Inherit the framework engine's configured recycling limit; polling must not
        // silently replace the operator's long-running gateway setting with 100,000.
    ],
    'RC_GATEWAY_API' => array_replace($apiProcess, [
        'listen' => 'http://127.0.0.1:' . $gateway['api_port'],
        'default_app' => 'gateway_entry',
        // Routing and streaming only: the ingress must not initialize SQLite or task state.
        'bootstrap' => [app\gateway\bootstrap\HttpLimits::class],
    ]),
    'RC_GATEWAY_NATIVE_API' => $apiProcess + [
        'listen' => 'http://127.0.0.1:' . $gateway['native_api_port'],
        'default_app' => 'gateway_native',
    ],
    'RC_GATEWAY_WEB_API' => $apiProcess + [
        'listen' => 'http://127.0.0.1:' . $gateway['web_api_port'],
        'default_app' => 'gateway',
    ],
    'RC_GATEWAY_PROBE' => [
        'handler' => support\process\GatewayProbe::class,
        'count' => 1,
        'reloadable' => false,
        'bootstrap' => [RC\Helper\Db\Medoo::class, RC\Helper\Cache\Raw::class, app\gateway\bootstrap\Initialize::class],
    ],
    'RC_GATEWAY_MAINTENANCE' => [
        'handler' => support\process\GatewayMaintenance::class,
        'count' => 1,
        'reloadable' => false,
        'bootstrap' => [RC\Helper\Db\Medoo::class, app\gateway\bootstrap\Initialize::class],
    ],
    'RC_GATEWAY_BROWSER' => [
        'handler' => support\process\GatewayBrowser::class,
        'count' => 1,
        'reloadable' => false,
        'bootstrap' => [RC\Helper\Db\Medoo::class, app\gateway\bootstrap\Initialize::class],
    ],
    'RC_GATEWAY_TUNNEL' => [
        'handler' => support\process\GatewayTunnel::class,
        'count' => 1,
        'reloadable' => false,
        'bootstrap' => [RC\Helper\Db\Medoo::class, app\gateway\bootstrap\Initialize::class],
    ],
    'RC_monitor' => [
        'handler' => RC\Helper\Process\FileMonitor::class,
        'count' => 1,
        'reloadable' => false,
        'bootstrap' => [],
        'constructor' => [
            'monitor_dir' => [
                // Development hot reload is explicit: touching this sentinel restarts the
                // reloadable workers. Recursively stat'ing the whole source tree every second
                // created measurable idle CPU on Windows and is unnecessary for the appliance.
                BASE_PATH . '/config/none.php',
            ],
            'monitor_extensions' => ['php', 'html', 'htm', 'js', 'css', 'json'],
        ],
    ],
];
