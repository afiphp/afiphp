<?php

declare(strict_types=1);

namespace Afiphp\Billing;

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
}
