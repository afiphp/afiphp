<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\ServiceStatus;
use DateTimeImmutable;

/** @internal Protocol boundary. Implementations must NEVER retry an authorization. */
interface FiscalGateway
{
    /** WSFE's health report (FEDummy). Does not log in. */
    public function status(): ServiceStatus;

    /**
     * An authenticated read, so it also proves the login and that WSFE is enabled for the CUIT.
     * @return list<\Afiphp\SalesPoint> Empty when ARCA lists none.
     */
    public function salesPoints(): array;

    public function lastNumber(int $type, int $salesPoint): int;

    public function authorize(array $request): object;

    /** FECompConsultar. Throws a WsException with only code 602 when the number is empty. */
    public function lookup(int $type, int $salesPoint, int $number): object;

    /** ARCA's published dollar rate, already validated as a Currency::dollars() string. */
    public function dollarRate(?DateTimeImmutable $date): string;
}
