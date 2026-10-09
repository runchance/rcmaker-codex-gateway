<?php
declare(strict_types=1);
namespace support\browser\adapter;

/** Provider-neutral metadata for one versioned Web model page adapter. */
interface WebModelAdapterInterface
{
    public static function id(): string;
    public static function version(): string;
    public static function provider(): string;
    /** @return class-string */
    public static function pageClass(): string;
    /** @return list<string> */
    public static function declaredCapabilities(): array;
}
