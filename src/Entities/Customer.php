<?php

namespace Afiphp\Entities;

class Customer
{
    protected int $documentType;
    protected string $documentNumber;

    public function __construct(int $documentType, string $documentNumber)
    {
        $this->documentType = $documentType;
        $this->documentNumber = $documentNumber;
    }

    /**
     * Get document type
     */
    public function getDocumentType(): int
    {
        return $this->documentType;
    }

    /**
     * Get document number
     */
    public function getDocumentNumber(): string
    {
        return $this->documentNumber;
    }
}
