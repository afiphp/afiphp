<?php

namespace Afiphp;

class Currency
{
    protected string $code;
    protected float $rate;

    public function __construct(string $code, float $rate)
    {
        $this->code = $code;
        $this->rate = $rate;
    }

    /**
     * Get code
     */ 
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get rate
     */ 
    public function getRate(): float
    {
        return $this->rate;
    }
}