<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * ARCA could not process the request: it did not answer, timed out or failed internally.
 * Waiting solves it. This side cannot tell "ARCA is down" from "this server has no connection".
 */
final class ArcaUnavailable extends RuntimeException
{
    /**
     * @param bool $sent False when the request never left this server; true when it may have reached ARCA.
     */
    public function __construct(
        string $message,
        public readonly bool $sent,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
