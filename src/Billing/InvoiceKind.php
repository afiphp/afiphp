<?php

declare(strict_types=1);

namespace Afiphp\Billing;

/** Backed by the persisted journal token: values must never change. */
enum InvoiceKind: string
{
    case INVOICE = 'INVOICE';
    case CREDIT_NOTE = 'CREDIT_NOTE';
    case DEBIT_NOTE = 'DEBIT_NOTE';
}
