<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use InvalidArgumentException;

final readonly class Receiver
{
    private function __construct(
        public VatCondition $vatCondition,
        public string $document,
        public DocumentType $documentType,
    ) {}

    public static function business(string $cuit, VatCondition $vatCondition): self
    {
        if ($vatCondition === VatCondition::CONSUMIDOR_FINAL) {
            throw new InvalidArgumentException('Use Receiver::finalConsumerWithCuit() for a final consumer.');
        }
        self::validateCuit($cuit);

        return new self($vatCondition, $cuit, DocumentType::CUIT);
    }

    public static function finalConsumerWithCuit(string $cuit): self
    {
        self::validateCuit($cuit);

        return new self(VatCondition::CONSUMIDOR_FINAL, $cuit, DocumentType::CUIT);
    }

    /** CUIL shares the CUIT format and check digit. */
    public static function finalConsumerWithCuil(string $cuil): self
    {
        self::validateCuit($cuil);

        return new self(VatCondition::CONSUMIDOR_FINAL, $cuil, DocumentType::CUIL);
    }

    /** Identification is deliberately mandatory: no silently outdated fiscal threshold. */
    public static function finalConsumerWithDni(string $dni): self
    {
        self::validateDni($dni);

        return new self(VatCondition::CONSUMIDOR_FINAL, $dni, DocumentType::DNI);
    }

    public static function validateDni(string $dni): void
    {
        if (!preg_match('/^[1-9][0-9]{0,10}$/D', $dni)) {
            throw new InvalidArgumentException(
                'DNI must be a positive number of at most 11 digits, without separators.',
            );
        }
    }

    public static function validateCuit(string $cuit): void
    {
        if (!preg_match('/^[0-9]{11}$/D', $cuit)) {
            throw new InvalidArgumentException('CUIT must contain exactly 11 digits without separators.');
        }
        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $index => $weight) {
            $sum += (int) $cuit[$index] * $weight;
        }
        $digit = 11 - ($sum % 11);
        $digit = $digit === 11 ? 0 : $digit;
        if ((int) $cuit[10] !== $digit || (int) $cuit === 0) {
            throw new InvalidArgumentException('CUIT has an invalid check digit.');
        }
    }
}
