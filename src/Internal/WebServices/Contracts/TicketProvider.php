<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices\Contracts;

use Afiphp\Internal\WebServices\Ticket;

/** @internal */
interface TicketProvider
{
    public function ticket(string $serviceName): Ticket;
}
