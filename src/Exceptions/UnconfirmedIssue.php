<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The voucher may exist with $number, but the attempt could not be confirmed.
 * Keep $number with the invoice and pass it to Arca::retryIssue() on the next attempt.
 */
final class UnconfirmedIssue extends RuntimeException
{
    public function __construct(
        public readonly int $number,
        ?Throwable $previous = null,
    ) {
        parent::__construct("ARCA did not confirm voucher number {$number}; it may exist.", 0, $previous);
    }
}
