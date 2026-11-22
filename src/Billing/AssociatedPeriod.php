<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

/** The period a note adjusts (PeriodoAsoc), instead of a specific original voucher. */
final readonly class AssociatedPeriod
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($from->format('Y-m-d') > $to->format('Y-m-d')) {
            throw new InvalidArgumentException('Associated period start must not be after its end.');
        }
    }
}
