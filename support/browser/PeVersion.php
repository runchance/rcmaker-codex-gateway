<?php
declare(strict_types=1);
namespace support\browser;

/** Bounded PHP reader for the Windows PE RT_VERSION resource; never executes the file. */
final class PeVersion
{
    /** @return array{product:string,version:string}|null Malformed or absent resources return null. */
    public static function read(string $path): ?array
    {
        $file = @fopen($path, 'rb');
        if ($file === false) return null;
        try {
            $size = (int) fstat($file)['size'];
            $read = static function (int $offset, int $length) use ($file, $size): string {
                if ($offset < 0 || $length < 1 || $length > 1048576 || $offset + $length > $size || fseek($file, $offset) !== 0) {
                    throw new \UnexpectedValueException('Invalid PE range.');
                }
                $bytes = fread($file, $length);
                if (strlen($bytes) !== $length) throw new \UnexpectedValueException('Truncated PE.');
                return $bytes;
            };
            $dos = $read(0, 64);
            if (substr($dos, 0, 2) !== 'MZ') return null;
            $peOffset = self::u32($dos, 60);
            $header = $read($peOffset, 24);
            if (substr($header, 0, 4) !== "PE\0\0") return null;
            $sections = self::u16($header, 6);
            $optionalSize = self::u16($header, 20);
            if ($sections < 1 || $sections > 96 || $optionalSize < 128) return null;
            $optional = $read($peOffset + 24, $optionalSize);
            $directory = match (self::u16($optional, 0)) { 0x10b => 96, 0x20b => 112, default => -1 };
            if ($directory < 0 || strlen($optional) < $directory + 24) return null;
            $resourceRva = self::u32($optional, $directory + 16);
            $table = $read($peOffset + 24 + $optionalSize, $sections * 40);
            $map = static function (int $rva, int $length) use ($table, $sections): int {
                for ($i = 0; $i < $sections; $i++) {
                    $base = $i * 40;
                    $address = self::u32($table, $base + 12);
                    $rawSize = self::u32($table, $base + 16);
                    if ($rva >= $address && $rva + $length <= $address + $rawSize) return self::u32($table, $base + 20) + $rva - $address;
                }
                throw new \UnexpectedValueException('Unmapped PE resource.');
            };
            $resourceFile = $map($resourceRva, 16);
            $relative = 0;
            for ($level = 0; $level < 3; $level++) {
                $node = $read($resourceFile + $relative, 16);
                $count = self::u16($node, 12) + self::u16($node, 14);
                if ($count < 1 || $count > 4096) return null;
                $entries = $read($resourceFile + $relative + 16, $count * 8);
                $entry = null;
                for ($i = 0; $i < $count; $i++) {
                    if ($level === 0 && self::u32($entries, $i * 8) !== 16) continue;
                    $entry = self::u32($entries, $i * 8 + 4);
                    break;
                }
                if ($entry === null || (($entry & 0x80000000) !== 0) !== ($level < 2)) return null;
                $relative = $entry & 0x7fffffff;
            }
            $data = $read($resourceFile + $relative, 16);
            $length = self::u32($data, 4);
            $resource = $read($map(self::u32($data, 0), $length), $length);
            $values = [];
            foreach (['ProductName' => 'product', 'ProductVersion' => 'version'] as $key => $field) {
                $needle = mb_convert_encoding($key, 'UTF-16LE', 'UTF-8') . "\0\0";
                $position = strpos($resource, $needle);
                if ($position === false || $position < 6) return null;
                $blockSize = self::u16($resource, $position - 6);
                $valueLength = self::u16($resource, $position - 4) * 2;
                $start = ($position + strlen($needle) + 3) & ~3;
                if ($valueLength < 2 || $start + $valueLength > $position - 6 + $blockSize || $position - 6 + $blockSize > strlen($resource)) return null;
                $values[$field] = trim(mb_convert_encoding(substr($resource, $start, $valueLength), 'UTF-8', 'UTF-16LE'), "\0 \r\n");
            }
            return $values;
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($file);
        }
    }

    /** Read one little-endian unsigned field after verifying its byte range. */
    private static function u32(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($bytes)) throw new \UnexpectedValueException('Truncated PE field.');
        return unpack('V', substr($bytes, $offset, 4))[1];
    }

    /** Read one little-endian unsigned short after verifying its byte range. */
    private static function u16(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($bytes)) throw new \UnexpectedValueException('Truncated PE field.');
        return unpack('v', substr($bytes, $offset, 2))[1];
    }
}
