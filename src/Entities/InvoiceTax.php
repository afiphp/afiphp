<?php

namespace Afiphp\Invoice;

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

    /**
     * Get code
     */ 
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Get taxable base
     */ 
    public function getTaxableBase(): float
    {
        return $this->taxableBase;
    }

    /**
     * Get amount
     */ 
    public function getAmount(): float
    {
        return $this->amount;
    }
}