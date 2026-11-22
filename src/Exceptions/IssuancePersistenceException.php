<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use Afiphp\Billing\IssueResult;
use RuntimeException;
use Throwable;

/** A fiscal result was obtained but could not be recorded. The durable claim remains unresolved. */
final class IssuancePersistenceException extends RuntimeException
{
    public function __construct(
        public readonly IssueResult $result,
        Throwable $previous,
    ) {
        parent::__construct(
            'Unable to persist the fiscal result. Keep this result and recover the same operation; '
            . 'never start a new one.',
            previous: $previous,
        );
    }
}
