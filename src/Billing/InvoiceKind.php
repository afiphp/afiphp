<?php

declare(strict_types=1);

namespace Afiphp\Billing;

enum InvoiceKind
{
    case INVOICE;
    case CREDIT_NOTE;
    case DEBIT_NOTE;
}
