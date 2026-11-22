<?php

declare(strict_types=1);

namespace Afiphp\Billing;

/**
 * A taxpayer as ARCA's registry (constancia de inscripción) reports it. Read-only data, never cached.
 * $vatCondition is null when the registry cannot decide it: conflicting VAT registrations, or a
 * constancia with errors (listed in $errors), which may be missing registrations.
 */
final readonly class Taxpayer
{
    /** @param list<string> $errors */
    public function __construct(
        public string $cuit,
        public ?string $name,
        public ?string $personType,
        public ?VatCondition $vatCondition,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?string $province = null,
        public array $errors = [],
    ) {}

    /** A receiver with the registry's condition; throws when the registry could not decide it. */
    public function receiver(): Receiver
    {
        return match ($this->vatCondition) {
            null => throw new \LogicException(
                'The registry did not establish a VAT condition; ask the receiver and use Receiver::business().',
            ),
            VatCondition::CONSUMIDOR_FINAL => Receiver::finalConsumerWithCuit($this->cuit),
            default => Receiver::business($this->cuit, $this->vatCondition),
        };
    }
}
