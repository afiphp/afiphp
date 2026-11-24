<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;

/** ARCA rejected the invoice: nothing was authorized. Fix what the observations say and issue it again. */
final class InvoiceRejected extends RuntimeException
{
    /** @param list<string> $observations */
    public function __construct(
        public readonly int $number,
        public readonly array $observations,
    ) {
        parent::__construct(
            $observations === []
                ? 'ARCA rejected the invoice without observations.'
                : 'ARCA rejected the invoice: ' . implode(' | ', $observations),
        );
    }
}
