<?php
/** Separate loopback APP groups plus isolated network, maintenance and browser workers. */
$gateway = RC\Config::get('gateway');
\support\process\GatewayLaunch::acquire();
return [
    'RC_GATEWAY_CONSOLE' => [
        'type' => 'app',
        'listen' => 'http://127.0.0.1:' . $gateway['console_port'],
        'count' => 1,
        'default_app' => 'console',
        'reusePort' => false,
        'memory_limit' => '128M',
        'max_request' => 100000,
    ],
    'RC_GATEWAY_API' => [
        'type' => 'app',
        'listen' => 'http://127.0.0.1:' . $gateway['api_port'],
        'count' => 1,
        'default_app' => 'gateway',
        'reusePort' => false,
        // JSON decoding and native forwarding need room for the existing 80 MB envelope.
        'memory_limit' => '512M',
        'package_max_length' => app\gateway\model\ResponsesProtocol::MAX_BODY_BYTES + 16384 + 4,
        'max_request' => 100000,
        'bootstrap' => [app\gateway\bootstrap\HttpLimits::class, RC\Helper\Db\Medoo::class, app\gateway\bootstrap\Initialize::class],
    ],
    'RC_GATEWAY_PROBE' => [
        'handler' => support\process\GatewayProbe::class,
        'count' => 1,
        'reloadable' => false,
        'bootstrap' => [RC\Helper\Db\Medoo::class, app\gateway\bootstrap\Initialize::class],
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
