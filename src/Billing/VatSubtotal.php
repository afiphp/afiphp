<?php

declare(strict_types=1);

namespace Afiphp\Billing;

/** Base and VAT amount in integer cents. */
final readonly class VatSubtotal
{
    public function __construct(
        public VatRate $rate,
        public int $base,
        public int $amount,
    ) {}
}
