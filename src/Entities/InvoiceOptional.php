<?php

namespace Afiphp\Entities;

class InvoiceOptional
{
    public string $code;
    public string $value;

    public function __construct(string $code, string $value)
    {
        $this->code = $code;
        $this->value = $value;
    }
}
