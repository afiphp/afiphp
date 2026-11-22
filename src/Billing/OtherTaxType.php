<?php

declare(strict_types=1);

namespace Afiphp\Billing;

/** Tributos (FEParamGetTiposTributos). Prefer the specific perception cases over the generic ones. */
enum OtherTaxType
{
    case NATIONAL; // 1  Impuestos nacionales
    case PROVINCIAL; // 2  Impuestos provinciales
    case MUNICIPAL; // 3  Impuestos municipales
    case INTERNAL; // 4  Impuestos internos
    case GROSS_INCOME; // 5  Ingresos Brutos
    case VAT_PERCEPTION; // 6  Percepción de IVA
    case GROSS_INCOME_PERCEPTION; // 7  Percepción de Ingresos Brutos
    case MUNICIPAL_PERCEPTION; // 8  Percepciones por impuestos municipales
    case OTHER_PERCEPTION; // 9  Otras percepciones
    case OTHER; // 99 Otro
}
