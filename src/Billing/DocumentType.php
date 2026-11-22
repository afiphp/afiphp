<?php

declare(strict_types=1);

namespace Afiphp\Billing;

/** Receiver identification. */
enum DocumentType
{
    case CUIT;
    case CUIL;
    case DNI;
}
