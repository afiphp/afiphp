<?php

namespace Afiphp;

use DateTime;

class SalesPoint
{
    protected int $number;
    protected string $type;
    protected bool $isLocked;
    protected ?DateTime $deletedAt;

    public function __construct(
        int $number,
        string $type,
        bool $isLocked,
        DateTime $deletedAt = null)
    {
        $this->number = $number;
        $this->type = $type;
        $this->isLocked = $isLocked;
        $this->deletedAt = $deletedAt;
    }

    /**
     * Get number
     */ 
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Get type
     */ 
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Is locked?
     */ 
    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    /**
     * Get deletedAt
     */ 
    public function getDeletedAt(): ?DateTime
    {
        return $this->deletedAt;
    }
}