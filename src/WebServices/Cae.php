<?php

namespace Afiphp\WebServices;

use DateTime;

class Cae
{
    protected string $result;
    protected int $invoiceType;
    protected int $salesPoint;
    protected int $firstInvoiceNumber;
    protected int $lastInvoiceNumber;
    protected ?string $number;
    protected ?DateTime $dueDate;
    protected array $observations;

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

    /**
     * Get result
     */ 
    public function getResult(): string
    {
        return $this->result;
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
     * Get first invoice number
     */ 
    public function getFirstInvoiceNumber(): int
    {
        return $this->firstInvoiceNumber;
    }

    /**
     * Get last invoice number
     */ 
    public function getLastInvoiceNumber(): int
    {
        return $this->lastInvoiceNumber;
    }

    /**
     * Get number
     */ 
    public function getNumber(): ?string
    {
        return $this->number;
    }

    /**
     * Get due date
     */ 
    public function getDueDate(): ?DateTime
    {
        return $this->dueDate;
    }

    /**
     * Get observations
     *
     * @return string[]
     */
    public function getObservations(): array
    {
        return $this->observations;
    }

    /**
     * Is approved?
     */
    public function isApproved(): bool
    {
        return $this->result === 'A';
    }

    /**
     * Is rejected?
     */
    public function isRejected(): bool
    {
        return $this->result === 'R';
    }

    /**
     * Is partial?
     */
    public function isPartial(): bool
    {
        return $this->result === 'P';
    }
}