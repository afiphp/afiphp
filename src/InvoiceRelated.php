<?php

namespace Afiphp;

use DateTime;

class InvoiceRelated
{
    protected int $invoiceType;
    protected int $salesPoint;
    protected int $number;
    protected ?string $cuit;
    protected ?DateTime $date;

    public function __construct(
        int $invoiceType,
        int $salesPoint,
        int $number,
        string $cuit = null,
        DateTime $date = null
    ) {
        $this->invoiceType = $invoiceType;
        $this->salesPoint = $salesPoint;
        $this->number = $number;
        $this->cuit = $cuit;
        $this->date = $date;
    }

    /**
     * Get invoice type
     */ 
    public function getInvoiceType(): int
    {
        return $this->invoiceType;
    }

    /**
     * Get sales point
     */ 
    public function getSalesPoint(): int
    {
        return $this->salesPoint;
    }

    /**
     * Get number
     */ 
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Get CUIT
     */ 
    public function getCuit(): ?string
    {
        return $this->cuit;
    }

    /**
     * Get date
     */ 
    public function getDate(): ?DateTime
    {
        return $this->date;
    }
}