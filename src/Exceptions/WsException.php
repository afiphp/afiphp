<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use Exception;
use Throwable;

class WsException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private array $errors = [],
        private ?object $response = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<int, object> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** Keeps observations and partial batch results available to the caller. */
    public function getResponse(): ?object
    {
        return $this->response;
    }
}
