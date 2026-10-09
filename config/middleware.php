<?php
return [
    'middleware' => ['gateway' => [support\middleware\GatewayLocal::class]],
    'static_middleware' => [support\middleware\GatewayConsole::class],
];
