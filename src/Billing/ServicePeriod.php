<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

/** Calendar dates use each value's day; time and timezone offsets are not compared. */
final readonly class ServicePeriod
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeImmutable $paymentDue,
        public bool $includesProducts = false,
    ) {
        if ($from->format('Y-m-d') > $to->format('Y-m-d')) {
            throw new InvalidArgumentException('Service start must not be after service end.');
        }
    }
}
