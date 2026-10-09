<?php
declare(strict_types=1);
namespace app\gateway\model;

/** A safe domain failure. Never put credentials or upstream response text in its message. */
final class GatewayError extends \RuntimeException
{
    /** @param string $errorCode Stable identifier. @param string $message Safe localized message. @param int $httpStatus Response status. */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}
