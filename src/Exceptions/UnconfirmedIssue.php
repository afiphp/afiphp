<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * ARCA's answer was lost and a lookup could not confirm it: the voucher may exist with $number.
 * Keep $number with the invoice and pass it to Arca::issue() as $previousNumber on the next attempt.
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
