<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use Afiphp\Internal\Billing\InvoiceMapper;
use InvalidArgumentException;

/** A fiscal assertion by the caller, not inferred from a document or a registry. */
enum VatCondition
{
    case RESPONSABLE_INSCRIPTO;
    case MONOTRIBUTISTA;
    case MONOTRIBUTISTA_SOCIAL;
    case MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO;
    case EXENTO;
    case NO_ALCANZADO;
    case CONSUMIDOR_FINAL;

    /** The condition for ARCA's receiver VAT condition code (CondicionIVAReceptorId), as stored by most systems. */
    public static function fromArcaCode(int $code): self
    {
        foreach (self::cases() as $condition) {
            if (InvoiceMapper::condition($condition) === $code) {
                return $condition;
            }
        }

        throw new InvalidArgumentException("Unsupported ARCA VAT condition code {$code}.");
    }
}
