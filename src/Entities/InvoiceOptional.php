<?php

namespace Afiphp\Entities;

class InvoiceOptional
{
    protected string $code;
    protected string $value;

    public function __construct(string $code, string $value)
    {
        $this->code = $code;
        $this->value = $value;
    }

    /**
     * Get code
     */ 
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get value
     */ 
    public function getValue(): string
    {
        return $this->value;
    }
}