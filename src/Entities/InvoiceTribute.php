<?php

namespace Afiphp\Entities;

class InvoiceTribute
{
    public int $code;
    public string $description;
    public float $taxableBase;
    public float $aliquot;
    public float $amount;

    public function __construct(
        int $code,
        string $description,
        float $taxableBase,
        float $aliquot,
        float $amount
    ) {
        $this->code = $code;
        $this->description = $description;
        $this->taxableBase = $taxableBase;
        $this->aliquot = $aliquot;
        $this->taxableBase = $taxableBase;
        $this->amount = $amount;
    }
}
