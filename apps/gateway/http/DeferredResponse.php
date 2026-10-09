<?php
declare(strict_types=1);
namespace app\gateway\http;

/** Marker returned to RCMAKER while a Workerman connection is intentionally answered later. */
final class DeferredResponse extends \RC\Http\Workerman\Response
{
    public function __construct(\RC\Request $request)
    {
        parent::__construct($request, 200, [], '');
    }

    public function __toString(): string
    {
        return '';
    }
}
