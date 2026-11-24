<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Authorization;
use Afiphp\Billing\Currency;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\Preview;
use Afiphp\Billing\Receiver;
use DateTimeImmutable;

/** @internal A voucher as FECompConsultar reports it, already validated and in afiphp's vocabulary. */
final readonly class FoundVoucher
{
    /**
     * @param list<array{id: int, description: string, base: int, percentage: string, amount: int}> $otherTaxes
     * @param array{from: DateTimeImmutable, to: DateTimeImmutable, due: DateTimeImmutable}|null $service
     * @param list<string> $observations
     * @param list<string> $unsupported Fiscal fields afiphp cannot reproduce, such as Opcionales.
     */
    public function __construct(
        public InvoiceClass $class,
        public InvoiceKind $kind,
        public int $salesPoint,
        public int $number,
        public DateTimeImmutable $date,
        public int $concept,
        public int $documentType,
        public string $document,
        public ?Receiver $receiver,
        public string $currencyCode,
        public ?Currency $currency,
        public Preview $totals,
        public array $otherTaxes,
        public ?array $service,
        public Authorization $authorization,
        public array $observations,
        public array $unsupported,
    ) {}

    public function reference(): InvoiceReference
    {
        return new InvoiceReference($this->class, $this->salesPoint, $this->number, $this->date, $this->kind);
    }
}
