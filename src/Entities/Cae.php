<?php

namespace Afiphp\Entities;

use DateTime;

class Cae
{
    public string $result;
    public int $invoiceType;
    public int $salesPoint;
    public int $firstInvoiceNumber;
    public int $lastInvoiceNumber;
    public ?string $number;
    public ?DateTime $dueDate;
    public array $observations;

    public function __construct(
        string $result,
        int $invoiceType,
        int $salesPoint,
        int $firstInvoiceNumber,
        int $lastInvoiceNumber,
        ?string $number,
        ?DateTime $dueDate,
        array $observations = []
    ) {
        $this->result = $result;
        $this->invoiceType = $invoiceType;
        $this->salesPoint = $salesPoint;
        $this->firstInvoiceNumber = $firstInvoiceNumber;
        $this->lastInvoiceNumber = $lastInvoiceNumber;
        $this->number = $number;
        $this->dueDate = $dueDate;
        $this->observations = $observations;
    }

    public function isApproved(): bool
    {
        return $this->result === 'A';
    }

    public function isRejected(): bool
    {
        return $this->result === 'R';
    }

    public function isPartial(): bool
    {
        return $this->result === 'P';
    }
}
