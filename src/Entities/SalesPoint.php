<?php

namespace Afiphp\Entities;

use DateTime;

class SalesPoint
{
    public int $number;
    public string $type;
    public bool $isLocked;
    public ?DateTime $lockedAt;

    public function __construct(
        int $number,
        string $type,
        bool $isLocked = false,
        DateTime $lockedAt = null
    ) {
        $this->number = $number;
        $this->type = $type;
        $this->isLocked = $isLocked;
        $this->lockedAt = $lockedAt;
    }
}
