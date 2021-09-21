<?php

namespace Afiphp\Entities;

use DateTime;

class InvoiceRelated
{
    public int $invoiceType;
    public int $salesPoint;
    public int $number;
    public ?string $cuit;
    public ?DateTime $date;

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
}
