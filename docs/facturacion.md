# Facturación

[Inicio](../README.md) · [Configuración](configuracion.md) · [Errores y reintentos](conciliacion.md)

Los ejemplos suponen un cliente `$arca` y esta fecha:

```php
$today = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
```

Para emitir cualquiera de estos comprobantes, llamá `$arca->issue($invoice)` y
tratá sus [excepciones](conciliacion.md#qué-puede-pasar-al-emitir).

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

$result = $arca->issue($note);
```

- Con `original:`, la nota toma su clase (para un original C, usá
  `Item::amount()` sin alícuota). Con `period:`, la clase se deriva de las
  condiciones actuales.
- Usá el mismo receptor, moneda y cotización que el original.
- Una nota de crédito no puede ajustar otra nota de crédito.
- **No se controla el saldo** de notas contra el original: eso queda en tu
  aplicación.
- Si no tenés el `IssueResult` del original, armá la referencia con
  `new InvoiceReference($clase, $puntoDeVenta, $numero, $fecha)`.

### Nota de crédito total

Para anular todo un comprobante, `fullCreditNote()` lo consulta en ARCA y arma
la nota que lo espeja: mismo receptor, moneda, cotización, importes por
alícuota, exento, no gravado y otros tributos. Devuelve un `Invoice` común, que
se emite con `issue()`:

```php
$note = $arca->fullCreditNote($authorized->invoiceReference(), $today);
$result = $arca->issue($note);
```

- Sirve para comprobantes de cualquier sistema, no solo de afiphp. La fecha de
  la referencia tiene que coincidir con la que tiene ARCA.
- Un tercer parámetro, `salesPoint:`, emite la nota en otro punto de venta.
- En servicios, el vencimiento de pago pasa a la fecha de la nota si el
  original ya venció.
- Lanza `InvalidArgumentException` si no puede reproducir los importes
  exactamente: un receptor sin identificar (documento 99), otra moneda, datos
  opcionales de ARCA o un IVA que no es la base por la alícuota redondeada una
  vez. En ese caso, armá la nota con ítems explícitos.
- Para un reintento con `previousNumber`, guardá la nota o volvé a armarla:
  sale igual mientras el original no cambie.

## Consultar comprobantes

```php
use Afiphp\Billing\InvoiceClass;

$last = $arca->lastNumber(2, InvoiceClass::B); // 0 si la serie está vacía.
$voucher = $arca->voucher(InvoiceClass::B, 2, $last); // null si no existe.
```

`voucher()` devuelve un `IssueResult` con lo que ARCA tiene: fecha, totales,
CAE, receptor, moneda y QR. Sirve para conciliar, reimprimir o migrar desde
otro sistema. `receiver` es `null` cuando ARCA lo tiene sin identificar
(documento 99). Lanza `UnexpectedValueException` para comprobantes que afiphp
no modela, como uno en euros o autorizado con CAEA. Las notas se consultan
con el cuarto parámetro, `kind:`.

## Fechas y validaciones

- Se usa el día calendario del `DateTimeImmutable`, sin convertir zonas.
- Al emitir, la fecha debe estar en la ventana de ARCA: productos ±5 días (sin
  pasar del fin de mes), servicios ±10 días. Tampoco puede ser anterior a la
  última factura autorizada de la serie: ARCA la rechaza.
- `preview()` solo calcula clase e importes: **no verifica fechas, numeración
  ni habilitaciones**.
- CUIT y CUIL deben tener dígito verificador válido; el DNI, hasta 11 dígitos.
- Se rechazan importes negativos y total cero.
