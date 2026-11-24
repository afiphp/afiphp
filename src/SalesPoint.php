<?php

declare(strict_types=1);

namespace Afiphp;

use DateTimeImmutable;

/** A sales point ARCA lists for web services (FEParamGetPtosVenta). */
final readonly class SalesPoint
{
    /**
     * @param string $emissionType As ARCA reports it, such as "CAE".
     * @internal Constructed from ARCA's response.
     */
    public function __construct(
        public int $number,
        public string $emissionType,
        public bool $blocked,
        public ?DateTimeImmutable $deregisteredOn,
    ) {}

    /** Neither blocked nor deregistered. */
    public function isActive(): bool
    {
        return !$this->blocked && $this->deregisteredOn === null;
    }
}
