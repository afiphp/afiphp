<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Authorization
{
    public function __construct(
        public string $cae,
        public DateTimeImmutable $expiresOn,
    ) {
        if (!preg_match('/^[0-9]{14}$/D', $cae) || $cae === '00000000000000') {
            throw new InvalidArgumentException('Invalid authorization code.');
        }
    }
}
