<?php

namespace Afiphp\WebServices;

class InvoiceIdentifier
{
    protected int $invoiceType;
    protected int $salesPoint;
    protected ?int $number;

    public function __construct(
        int $invoiceType,
        int $salesPoint,
        int $number = null
    ) {
        $this->invoiceType = $invoiceType;
        $this->salesPoint = $salesPoint;
        $this->number = $number;
    }

    public function getInvoiceType(): int
    {
        return $this->invoiceType;
    }

    public function getSalesPoint(): int
    {
        return $this->salesPoint;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function withNumber(int $value): InvoiceIdentifier
    {
        $copy = clone $this;
        $copy->number = $value;

        return $copy;
    }
}