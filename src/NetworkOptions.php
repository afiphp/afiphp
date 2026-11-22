<?php

declare(strict_types=1);

namespace Afiphp;

use InvalidArgumentException;

final readonly class NetworkOptions
{
    public function __construct(
        public int $connectionTimeout = 10,
        public int $readTimeout = 60,
        public bool $saveSoapMessages = false,
        public int $lockTimeout = 10,
        public bool $allowLegacyTls = false,
    ) {
        if ($connectionTimeout < 1 || $readTimeout < 1 || $lockTimeout < 1) {
            throw new InvalidArgumentException('Timeouts must be positive seconds.');
        }
    }
}
