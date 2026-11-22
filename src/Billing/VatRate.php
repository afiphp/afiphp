<?php

declare(strict_types=1);

namespace Afiphp\Billing;

enum VatRate
{
    case ZERO;
    case REDUCED_2_5;
    case REDUCED_5;
    case REDUCED_10_5;
    case STANDARD_21;
    case INCREASED_27;
    case EXEMPT;
    case UNTAXED;
}
