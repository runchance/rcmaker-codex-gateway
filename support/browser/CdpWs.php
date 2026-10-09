<?php
declare(strict_types=1);
namespace support\browser;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Ws;

/** Workerman WebSocket client protocol with RFC 6455 random client masking for Chromium CDP. */
final class CdpWs extends Ws
{
    public static function encode(string $payload, AsyncTcpConnection $connection): string
    {
        $connection->websocketType ??= self::BINARY_TYPE_BLOB;
        $connection->websocketOrigin ??= null;
        $connection->websocketClientProtocol ??= null;
        if (!isset($connection->context->handshakeStep)) {
            static::sendHandshake($connection);
        }
        $length = strlen($payload);
        $head = match (true) {
            $length < 126 => chr(0x80 | $length),
            $length < 0xFFFF => chr(0x80 | 126) . pack('n', $length),
            default => chr(0x80 | 127) . pack('N', 0) . pack('N', $length),
        };
        $maskKey = random_bytes(4);
        $mask = str_repeat($maskKey, intdiv($length, 4)) . substr($maskKey, 0, $length % 4);
        $frame = $connection->websocketType . $head . $maskKey . ($payload ^ $mask);
        if ($connection->context->handshakeStep === 1) {
            if (strlen($connection->context->tmpWebsocketData) + strlen($frame) > $connection->maxSendBufferSize) return '';
            $connection->context->tmpWebsocketData .= $frame;
            return '';
        }
        return $frame;
    }
}
