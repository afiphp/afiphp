<?php

declare(strict_types=1);

namespace Tests\Support;

use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use Closure;

final class FakeTransport implements SoapTransport
{
    public array $calls = [];
    public ?string $request = null;
    public ?string $response = null;

    public function __construct(
        private ?Closure $handler = null,
    ) {}

    public function call(string $method, array $params): object
    {
        $this->calls[] = [$method, $params];

        return $this->handler !== null ? ($this->handler)($method, $params) : new \stdClass();
    }

    public function getLastRequest(): ?string
    {
        return $this->request;
    }

    public function getLastResponse(): ?string
    {
        return $this->response;
    }
}
