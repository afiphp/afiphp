<?php

namespace Afiphp\WebServices\Dtos;

use DateTime;

class CAE
{
    protected string $result;
    protected int $invoiceType;
    protected int $salesPoint;
    protected int $fromInvoiceNumber;
    protected int $toInvoiceNumber;
    protected ?string $number;
    protected ?DateTime $dueDate;
    protected array $observations;

    public function __construct(
        string $result,
        int $invoiceType,
        int $salesPoint,
        int $fromInvoiceNumber,
        int $toInvoiceNumber,
        ?string $number,
        ?DateTime $dueDate,
        array $observations = []
    ) {
        $this->result = $result;
        $this->invoiceType = $invoiceType;
        $this->salesPoint = $salesPoint;
        $this->fromInvoiceNumber = $fromInvoiceNumber;
        $this->toInvoiceNumber = $toInvoiceNumber;
        $this->number = $number;
        $this->dueDate = $dueDate;
        $this->observations = $observations;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getInvoiceType(): ?int
    {
        return $this->invoiceType;
    }

    public function getSalesPoint(): ?int
    {
        return $this->salesPoint;
    }

    public function getFromInvoiceNumber(): ?int
    {
        return $this->fromInvoiceNumber;
    }

    public function getToInvoiceNumber(): ?int
    {
        return $this->toInvoiceNumber;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getDueDate(): ?DateTime
    {
        return $this->dueDate;
    }
    
    public function getObservations(): array
    {
        return $this->observations;
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