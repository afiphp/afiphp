# Facturación

[Inicio](../README.md) · [Configuración](configuracion.md) · [Conciliación](conciliacion.md)

Los ejemplos suponen un cliente `$arca` y esta fecha:

```php
$today = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
```

Para emitir cualquiera de estos comprobantes, guardalo con su ID, llamá
`$arca->issue($invoice, 'venta:123')` y tratá el
[estado del resultado](conciliacion.md#qué-hacer-con-cada-estado).

## Importes

Todos los importes son **`int` en centavos** de la moneda del comprobante:
`15000` es $150,00 o USD 150,00. Se rechazan `float` y strings.

ARCA (WSFEv1) solo recibe totales por alícuota, no el detalle de ítems. Cada
`Item` es un importe final, con la cantidad y los descuentos ya aplicados; la
librería los agrupa por alícuota para calcular el IVA y los totales.

`Item::amount()` es lo que paga el cliente. El tipo de ítem depende solo de tu
condición frente al IVA, no del receptor:

| Emisor | Ítem | Total |
|---|---|---|
| Monotributista o exento | `Item::amount(12100)` | 12100 |
| Responsable inscripto | `Item::amount(12100, VatRate::STANDARD_21)` | 12100 |
| Responsable inscripto que cotiza sin IVA | `Item::netAmount(10000, VatRate::STANDARD_21)` | 12100 |

Una nota de crédito o débito sigue la clase del comprobante original: sobre una
factura C, los ítems van sin alícuota aunque hoy seas responsable inscripto.

Exento (`VatRate::EXEMPT`) y no gravado (`VatRate::UNTAXED`) no equivalen a
`VatRate::ZERO`. Ver [todas las alícuotas](referencia.md#enums).

El redondeo agrupa por alícuota antes de redondear y usa *half up*
(1,005 → 1,01).

## Clase del comprobante

La letra no se elige: se deriva de las condiciones de IVA.

| Emisor | Receptor | Clase |
|---|---|---|
| Responsable inscripto | Responsable inscripto o monotributista | A |
| Responsable inscripto | Cualquier otro | B |
| Monotributista, exento, etc. | Cualquiera | C |

```php
use Afiphp\Billing\{Invoice, Item, Receiver, VatCondition, VatRate};

// Emisor responsable inscripto → A.
$invoice = new Invoice(
    salesPoint: 2,
    receiver: Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
    items: [Item::netAmount(15000, VatRate::STANDARD_21)],
    date: $today,
);

// Emisor monotributista → C. Sin alícuota.
$invoice = new Invoice(
    salesPoint: 2,
    receiver: Receiver::finalConsumerWithDni('12345678'),
    items: [Item::amount(15000)],
    date: $today,
);

$arca->preview($invoice)->class->value; // 'C'
```

Receptores: `Receiver::business($cuit, $condicion)` o, para consumidor final,
`finalConsumerWithDni()`, `finalConsumerWithCuil()` y `finalConsumerWithCuit()`.
**No se admite consumidor final sin identificar.**

## IVA incluido

```php
$totals = $arca->preview(new Invoice(
    salesPoint: 2,
    receiver: Receiver::finalConsumerWithDni('12345678'),
    items: [Item::amount(18150, VatRate::STANDARD_21)],
    date: $today,
));
// net 15000, vat 3150, total 18150. Desglose en $totals->vatByRate.
```

## Servicios en dólares

```php
use Afiphp\Billing\{Currency, ServicePeriod};

$invoice = new Invoice(
    salesPoint: 2,
    receiver: Receiver::finalConsumerWithDni('12345678'),
    items: [Item::netAmount(10000, VatRate::STANDARD_21)], // USD 100,00 + IVA.
    date: $today,
    service: new ServicePeriod(
        from: $today->modify('first day of this month'),
        to: $today,
        paymentDue: $today->modify('+15 days'),
        includesProducts: false, // true: productos y servicios.
    ),
    currency: Currency::dollars('1234.567890', paidInDollars: true),
);
```

- Sin `ServicePeriod`, el comprobante es de productos. Sin `currency`, es en pesos.
- Los importes van en **centavos de dólar**, no convertidos.
- La cotización es un string decimal. `$arca->dollarRate()` (o
  `dollarRate($fecha)`) devuelve la publicada por ARCA. **Guardá la cotización
  elegida** con la operación; no la vuelvas a consultar para reintentar.

## Otros tributos

La aplicación decide qué tributos corresponden; la biblioteca calcula el importe.

```php
use Afiphp\Billing\{OtherTax, OtherTaxType};

$invoice = new Invoice(
    salesPoint: 2,
    receiver: Receiver::finalConsumerWithDni('12345678'),
    items: [Item::netAmount(10000, VatRate::STANDARD_21)],
    date: $today,
    otherTaxes: [
        new OtherTax(OtherTaxType::PROVINCIAL, 'Tributo de ejemplo', base: 10000, percentage: '3.00'),
    ],
);
// net 10000, vat 2100, otherTaxes 300, total 12400.
```

Porcentaje de `0` a `999.99`; descripción de hasta 80 bytes. La percepción de
IVA no se admite en clase C.

## Consultar al receptor

Requiere [servicios del Padrón](configuracion.md#padrón-opcional).

```php
$taxpayer = $arca->taxpayer('20111111112'); // null si no existe.
if ($taxpayer?->vatCondition !== null) {
    $receiver = $taxpayer->receiver();
}

$cuits = $arca->cuitsByDni('12345678'); // Puede devolver varias.
```

`vatCondition` se deriva de las inscripciones activas:

| Inscripción | 30 | 20 | 32 | 34 | Ninguna |
|---|---|---|---|---|---|
| Condición | `RESPONSABLE_INSCRIPTO` | `MONOTRIBUTISTA` | `EXENTO` | `NO_ALCANZADO` | `CONSUMIDOR_FINAL` |

Si son contradictorias o la constancia tiene errores, es `null` y `receiver()`
lanza `LogicException`: confirmá la condición con el receptor. Si un DNI tiene
varias CUIT, no elijas la primera. Las respuestas no se cachean.

## Notas de crédito y débito

### A partir de una operación propia

```php
$full = $arca->fullCreditNote('venta:123', date: $today);
$partial = $arca->creditNote('venta:123', [Item::netAmount(5000, VatRate::STANDARD_21)], date: $today);
$debit = $arca->debitNote('venta:123', [Item::netAmount(1000, VatRate::STANDARD_21)], date: $today);

$result = $arca->issue($partial, 'venta:123:nc:1');
```

- Leen el registro local; no emiten. El original debe estar `AUTHORIZED`.
- Heredan receptor, moneda, cotización, servicio, punto de venta y **clase**
  (para un original C, usá `Item::amount()` sin alícuota).
- La nota total copia ítems y tributos. En las parciales, pasá los tributos con
  `otherTaxes:`; no se prorratean.
- Una nota de crédito no supera el total del original, pero **no se controla
  el saldo** acumulado de notas: eso queda en tu aplicación.
- Guardá la nota antes de emitir: reconstruirla otro día cambia la fecha y
  produce un conflicto de idempotencia.

### Manualmente

Una nota ajusta un comprobante (`original:`) o un período (`period:`), nunca
ambos:

```php
use Afiphp\Billing\{AssociatedPeriod, InvoiceKind};

$note = new Invoice(
    salesPoint: $authorized->salesPoint,
    receiver: Receiver::finalConsumerWithDni('12345678'), // El del original.
    items: [Item::netAmount(5000, VatRate::STANDARD_21)],
    date: $today,
    kind: InvoiceKind::CREDIT_NOTE,
    original: $authorized->invoiceReference(),
    // o: period: new AssociatedPeriod($desde, $hasta),
);
```

Con `original:`, la nota toma su clase. Con `period:`, la clase se deriva de las
condiciones actuales. Una nota de crédito no puede ajustar otra nota de crédito.

## Fechas y validaciones

- Se usa el día calendario del `DateTimeImmutable`, sin convertir zonas.
- Al emitir, la fecha debe estar en la ventana de ARCA: productos ±5 días (sin
  pasar del fin de mes), servicios ±10 días. Tampoco puede ser anterior a otra
  autorización local de la misma serie.
- `preview()` solo calcula clase e importes: **no verifica fechas, numeración
  ni habilitaciones**.
- CUIT y CUIL deben tener dígito verificador válido; el DNI, hasta 11 dígitos.
- Se rechazan importes negativos y total cero.
