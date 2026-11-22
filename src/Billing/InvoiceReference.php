<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

/** An authorized voucher of the same issuer that a note adjusts. Usually an invoice; see Invoice for the rules. */
final readonly class InvoiceReference
{
    public function __construct(
        public InvoiceClass $class,
        public int $salesPoint,
        public int $number,
        public DateTimeImmutable $date,
        public InvoiceKind $kind = InvoiceKind::INVOICE,
    ) {
        if ($salesPoint < 1 || $salesPoint > 99999 || $number < 1 || $number > 99999999) {
            throw new InvalidArgumentException('Invalid associated invoice coordinates.');
        }
    }
}
