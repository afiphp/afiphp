<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use Afiphp\Internal\Billing\InvoiceMapper;

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

    /** The rate for ARCA's VAT rate code (Iva Id). Exempt and untaxed amounts have no code. */
    public static function fromArcaCode(int $code): self
    {
        return InvoiceMapper::rateFromCode($code);
    }
}
