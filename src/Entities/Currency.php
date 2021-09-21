<?php

namespace Afiphp\Entities;

use DateTime;

class Currency
{
    public string $code;
    public float $rate;
    public ?DateTime $rateAt;

    public function __construct(
        string $code,
        float $rate,
        DateTime $rateAt = null)
    {
        $this->code = $code;
        $this->rate = $rate;
        $this->rateAt = $rateAt;
    }
}
