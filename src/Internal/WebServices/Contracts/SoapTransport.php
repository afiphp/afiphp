<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices\Contracts;

/** @internal */
interface SoapTransport
{
    public function call(string $method, array $params): object;

    public function getLastRequest(): ?string;

    public function getLastResponse(): ?string;
}
