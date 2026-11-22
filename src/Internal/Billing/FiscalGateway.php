<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use DateTimeImmutable;

/** @internal Protocol boundary. Implementations must NEVER retry an authorization. */
interface FiscalGateway
{
    public function lastNumber(int $type, int $salesPoint): int;

    public function authorize(array $request): object;

    public function lookup(int $type, int $salesPoint, int $number): object;

    /** ARCA's published dollar rate, already validated as a Currency::dollars() string. */
    public function dollarRate(?DateTimeImmutable $date): string;
}
