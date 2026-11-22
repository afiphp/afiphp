<?php

declare(strict_types=1);

namespace Afiphp\Billing;

/** Pure calculation in integer cents: no login, number allocation, or fiscal side effects. */
final readonly class Preview
{
    /** @param list<VatSubtotal> $vatByRate */
    public function __construct(
        public InvoiceClass $class,
        public int $net,
        public int $vat,
        public int $exempt,
        public int $untaxed,
        public int $total,
        public array $vatByRate,
        public int $otherTaxes,
    ) {}
}
