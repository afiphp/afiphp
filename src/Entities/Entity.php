<?php

namespace Afiphp\Entities;

use DateTime;

class Entity
{
    public string $code;
    public string $description;
    public ?DateTime $fromDate;
    public ?DateTime $toDate;

    public function __construct(
        string $code,
        string $description,
        ?DateTime $fromDate,
        ?DateTime $toDate
    ) {
        $this->code = $code;
        $this->description = $description;
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
    }
}
