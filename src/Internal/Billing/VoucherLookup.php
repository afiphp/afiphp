<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

/** @internal Lookup outcomes without a matching IssueResult. */
enum VoucherLookup
{
    case Empty;
    case Other;
}
