<?php

namespace Afiphp;

class InvoiceTribute
{
    protected int $code;
    protected string $description;
    protected float $taxableBase;
    protected float $aliquot;
    protected float $amount;

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

    /**
     * Get code
     */ 
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Get description
     */ 
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get taxable base
     */ 
    public function getTaxableBase(): float
    {
        return $this->taxableBase;
    }

    /**
     * Get aliquot
     */ 
    public function getAliquot(): float
    {
        return $this->aliquot;
    }

    /**
     * Get amount
     */ 
    public function getAmount(): float
    {
        return $this->amount;
    }
}