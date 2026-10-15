<?php
return [
    'middleware' => [
        'gateway' => [support\middleware\GatewayLocal::class],
        'gateway_entry' => [support\middleware\GatewayLocal::class],
        'gateway_native' => [support\middleware\GatewayLocal::class],
    ],
    'static_middleware' => [support\middleware\GatewayConsole::class],
];
