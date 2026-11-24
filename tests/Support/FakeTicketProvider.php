<?php

declare(strict_types=1);

namespace Tests\Support;

use Afiphp\Internal\WebServices\Contracts\TicketProvider;
use Afiphp\Internal\WebServices\Ticket;

final class FakeTicketProvider implements TicketProvider
{
    public int $calls = 0;
    public ?\Throwable $failure = null;

    public function __construct(
        private array $tickets = [],
    ) {}

    public function ticket(string $serviceName): Ticket
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_shift($this->tickets) ?? new Ticket('token', 'sign');
    }
}
