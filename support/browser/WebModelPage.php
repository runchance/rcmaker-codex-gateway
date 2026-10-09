<?php
declare(strict_types=1);
namespace support\browser;

use support\browser\adapter\WebModelAdapterRegistry;

/** Dynamic page-contract proxy. BrowserRuntime depends on this boundary, not a provider DOM class. */
final class WebModelPage
{
    public static function __callStatic(string $name, array $arguments): mixed
    {
        $page = WebModelAdapterRegistry::pageClass();
        if (!is_callable([$page, $name])) throw new \BadMethodCallException('Unsupported Web model page operation: ' . $name);
        return $page::$name(...$arguments);
    }
}
