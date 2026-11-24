<?php

declare(strict_types=1);

namespace Afiphp\Printing;

use Afiphp\Billing\VatRate;

/** One line of the printed voucher. Amounts in integer cents: without VAT on class A, with VAT on class B. */
final readonly class PrintableLine
{
    /**
     * @param VatRate|null $vat Class A only: the line's rate, including EXEMPT and UNTAXED.
     * @internal Constructed by PrintableInvoice::build().
     */
    public function __construct(
        public string $description,
        public string $quantity,
        public int $unitPrice,
        public int $discount,
        public int $amount,
        public ?VatRate $vat,
        public ?string $code,
        public ?string $unit,
    ) {}
}
