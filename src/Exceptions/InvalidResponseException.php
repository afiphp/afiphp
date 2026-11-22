<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use Throwable;
use UnexpectedValueException;

/** A protocol failure is not evidence that ARCA rejected an authorization. */
class InvalidResponseException extends UnexpectedValueException
{
    public function __construct(
        string $message,
        private ?object $response = null,
        private ?string $operation = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getResponse(): ?object
    {
        return $this->response;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }
}
