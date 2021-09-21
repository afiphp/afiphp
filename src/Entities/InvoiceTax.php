<?php

namespace Afiphp\Entities;

class InvoiceTax
{
    public int $code;
    public float $taxableBase;
    public float $amount;

    public function __construct(
        int $code,
        float $taxableBase,
        float $amount
    ) {
        $this->code = $code;
        $this->taxableBase = $taxableBase;
        $this->amount = $amount;
    }
}
