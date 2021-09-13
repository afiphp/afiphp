<?php

namespace Afiphp\WebServices;

use DateTime;

class CAE
{
    protected string $result;
    protected int $fromInvoiceNumber;
    protected int $toInvoiceNumber;
    protected ?string $number;
    protected ?DateTime $dueDate;
    protected array $observations;

    public function __construct(
        string $result,
        int $fromInvoiceNumber,
        int $toInvoiceNumber,
        ?string $number,
        ?DateTime $dueDate,
        array $observations = []
    ) {
        $this->result = $result;
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