<?php

namespace Afiphp\WebServices\Dtos;

use DateTime;

class SalesPoint
{
    protected int $number;
    protected string $type;
    protected bool $isLocked;
    protected ?DateTime $deletedAt;

    public function __construct(string $number, string $type, bool $locked, ?DateTime $deletedAt)
    {
        $this->number = $number;
        $this->type = $type;
        $this->isLocked = $locked;
        $this->deletedAt = $deletedAt;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }

    public function getDeletedAt(): ?DateTime
    {
        return $this->deletedAt;
    }
}