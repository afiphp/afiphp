<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;

/**
 * ARCA's sequence contradicts an unconfirmed number: it is ahead of the last authorized number, or ARCA
 * reports it as used but cannot find it. Retrying cannot settle it, and nothing was sent. Reconcile
 * $number with ARCA by hand before issuing the invoice again.
 */
final class NeedsReconciliation extends RuntimeException
{
    public function __construct(
        public readonly int $number,
        string $message,
    ) {
        parent::__construct($message);
    }
}
