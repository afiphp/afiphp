<?php

declare(strict_types=1);

namespace Afiphp\Billing;

enum Outcome: string
{
    case AUTHORIZED = 'authorized';
    case REJECTED = 'rejected';
    case INDETERMINATE = 'indeterminate';
    case CONFLICT = 'conflict';
    case BLOCKED = 'blocked';
}
