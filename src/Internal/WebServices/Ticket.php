<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use DateTimeImmutable;

/** @internal WSAA access ticket (TA): the token/sign pair sent in every authenticated WSFE call. */
readonly class Ticket
{
    public function __construct(
        public string $token,
        public string $sign,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function isValid(): bool
    {
        return $this->expiresAt === null || $this->expiresAt->getTimestamp() > time();
    }
}
