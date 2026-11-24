<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;

/** A voucher ARCA authorized: just issued, or looked up with Arca::voucher(). Totals are in integer cents. */
final readonly class IssueResult
{
    /**
     * @param list<string> $observations ARCA's remarks on an authorized invoice; review them anyway.
     * @param string $qr RG 4892 QR URL for the printed voucher.
     * @param Receiver|null $receiver Null only for a looked-up voucher whose receiver afiphp cannot represent,
     *     such as an unidentified final consumer (document type 99).
     * @internal Constructed by Arca.
     */
    public function __construct(
        public InvoiceClass $class,
        public InvoiceKind $kind,
        public int $salesPoint,
        public int $number,
        public DateTimeImmutable $date,
        public Preview $totals,
        public Authorization $authorization,
        public array $observations,
        public string $qr,
        public ?Receiver $receiver,
        public Currency $currency,
    ) {}

    public function invoiceReference(): InvoiceReference
    {
        return new InvoiceReference($this->class, $this->salesPoint, $this->number, $this->date, $this->kind);
    }
}
