<?php
/** Product storage has no database-server credentials or fallback drivers. */
return [
    'default_frame' => 'medoo',
    'default' => 'sqlite',
    'driver' => [
        'sqlite' => [
            'database' => RC\Config::get('gateway', 'database_file'),
            'prefix' => '',
            'options' => ['option' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]],
        ],
    ],
];
