<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

/** WSFE answered with one of its internal errors (500, 501 or 502), not a validation error. */
final class ServiceErrorException extends WsException implements ServiceUnavailable
{
    public const CODES = [500, 501, 502];
}
