<?php
declare(strict_types=1);
namespace support\browser;

/** Normalizes the host OS/CPU into one Chrome for Testing platform identifier. */
final class BrowserPlatform
{
    /** @return array{os:string,arch:string,cft_platform:?string} */
    public static function detect(): array
    {
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
        $machine = php_uname('m');
        if ($os === 'windows') {
            // PROCESSOR_ARCHITEW6432 exposes the native OS architecture when 32-bit PHP runs on 64-bit Windows.
            $machine = (string) (getenv('PROCESSOR_ARCHITEW6432') ?: getenv('PROCESSOR_ARCHITECTURE') ?: $machine);
        }
        return self::from($os, $machine);
    }

    /** Deterministic mapper used by acceptance tests. @return array{os:string,arch:string,cft_platform:?string} */
    public static function from(string $os, string $machine): array
    {
        $os = match (strtolower(trim($os))) {
            'windows', 'win', 'winnt' => 'windows',
            'darwin', 'mac', 'macos' => 'macos',
            'linux' => 'linux',
            default => strtolower(trim($os)),
        };
        $arch = self::normalizeArch($machine);
        $platform = match ($os . ':' . $arch) {
            'windows:x64' => 'win64',
            'windows:x86' => 'win32',
            'macos:x64' => 'mac-x64',
            'macos:arm64' => 'mac-arm64',
            'linux:x64' => 'linux64',
            'linux:arm64' => 'linux-arm64',
            default => null,
        };
        // Chrome for Testing currently has no Windows ARM64 package. Never substitute win64.
        if ($os === 'windows' && $arch === 'arm64') $platform = null;
        return ['os' => $os, 'arch' => $arch, 'cft_platform' => $platform];
    }

    public static function normalizeArch(string $machine): string
    {
        $value = strtolower(trim($machine));
        return match ($value) {
            'amd64', 'x86_64', 'x64' => 'x64',
            'arm64', 'aarch64' => 'arm64',
            'x86', 'i386', 'i486', 'i586', 'i686' => 'x86',
            default => $value !== '' ? $value : 'unknown',
        };
    }
}
