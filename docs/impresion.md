# Comprobante impreso

[Inicio](../README.md) · [Facturación](facturacion.md) · [Referencia](referencia.md#impresión)

ARCA no genera el PDF de un comprobante emitido por web service. Lo arma tu
sistema, y las normas fijan qué datos lleva. `PrintableInvoice::build()` toma
la factura, lo que ARCA autorizó y los datos que ARCA no conoce, y devuelve
todo lo que el comprobante tiene que mostrar, ya calculado y controlado. No
dibuja nada: lo pasás a tu plantilla (Twig, Blade, HTML para un mail, Dompdf,
un ticket).

## Del comprobante autorizado al modelo

```php
use Afiphp\Printing\{IssuerDetails, PrintableInvoice, PrintedLine, ReceiverDetails};

$invoice = new Invoice(
    salesPoint: 2,
    receiver: Receiver::finalConsumerWithDni('12345678'),
    items: [
        Item::amount(121000, VatRate::STANDARD_21),
        Item::amount(2420, VatRate::REDUCED_10_5),
    ],
    date: $today,
);
$result = $arca->issue($invoice);

$printable = PrintableInvoice::build(
    invoice: $invoice,
    result: $result,
    issuer: new IssuerDetails(
        cuit: '20111111112',
        vatCondition: VatCondition::RESPONSABLE_INSCRIPTO,
        legalName: 'Ferretería del Sur SRL',
        address: 'Av. Rivadavia 1234, CABA',
        grossIncome: '901-123456-7',
        activitiesStart: new DateTimeImmutable('2019-10-01'),
    ),
    lines: [ // Una por ítem, en el mismo orden.
        new PrintedLine('Taladro percutor 13 mm', quantity: '2', unitPrice: 60500),
        new PrintedLine('Mechas para metal'),
    ],
    saleConditions: 'Contado',
    receiver: new ReceiverDetails(name: 'Juan Pérez', address: 'Calle 1 123'), // Opcional para consumidor final.
);

echo $twig->render('factura.html.twig', ['f' => $printable]);
```

`build()` controla que la factura sea la que ARCA autorizó (mismos importes,
punto de venta, receptor y moneda) y que la CUIT del emisor sea la del QR. Si
falta un dato obligatorio o algo no coincide, lanza `InvalidArgumentException`.

### Reimprimir

Guardá con la venta el `Invoice` (o sus datos) y las descripciones. Para
reimprimir, pasá lo que devuelve `voucher()` en lugar del resultado de la
emisión:

```php
$result = $arca->voucher(InvoiceClass::B, 2, $numero);
$printable = PrintableInvoice::build($invoice, $result, $issuer, $lines, 'Contado');
```

## Qué te pide

| Dato | Qué es |
|---|---|
| `IssuerDetails` | CUIT y condición (las de `Configuration`), razón social, domicilio comercial, ingresos brutos (o la condición de no contribuyente) e inicio de actividades. `activitiesStart` es `null` solo para profesionales universitarios que facturan honorarios y quien presta servicios sin local. Opcionales: `tradeName` (nombre de fantasía) y `paymentToInformedCbu` (RG 5762). |
| `PrintedLine` | Descripción y, opcionales, `quantity` (decimal como string: `'1.5'`), `unitPrice`, `discount`, `code` y `unit`. |
| `saleConditions` | Contado, cuenta corriente o la que corresponda. |
| `receiver` | Nombre y domicilio. Obligatorios salvo para consumidor final. |
| `remitos` | Los remitos de la operación, si los hay. |

`unitPrice` y `discount` van en centavos, **sin IVA en la clase A y con IVA en
la B**, como las líneas. Sin `unitPrice`, se calcula del importe de la línea;
si lo das, cantidad × precio − descuento tiene que dar el importe de la línea
con un centavo de diferencia como mucho.

## Qué devuelve

- `letter`, `code` (`'006'`), `title` (`FACTURA`, `NOTA DE CRÉDITO`, …) y
  `number` (`'00002-00000050'`). `letterLegend` es `PAGO EN CBU INFORMADA` en
  una A con `paymentToInformedCbu`.
- `issuerConditionLegend` y `receiverConditionLegend`, como
  `IVA RESPONSABLE INSCRIPTO` o `A CONSUMIDOR FINAL`, y el documento del
  receptor.
- `lines`: sin IVA y con su alícuota en la clase A, con IVA en la B, como se
  emitieron en la C. **Suman exactamente lo que autorizó ARCA** por alícuota: si
  hace falta, un centavo de redondeo va a una línea calculada, no a una que
  diste sin IVA.
- `subtotal` (en A, el neto gravado), `exempt`, `untaxed`, `vatByRate` (solo
  en A), `otherTaxes` y `total`.
- En la clase B a consumidor final, exento o no alcanzado,
  `transparencyVat` (IVA contenido) y `transparencyOtherTaxes` (otros impuestos
  nacionales indirectos: tributos nacionales e internos), del Régimen de
  Transparencia Fiscal al Consumidor (Ley 27.743).
- `cae`, `caeDueDate` y `qr`.
- `legends`: en una A a un monotributista, la de la Ley 27.618; en una A con
  observaciones de ARCA, sus códigos.

Las constantes de `PrintableInvoice` tienen los textos fijos como los escriben
las normas: `CAE_LABEL`, `CAE_DUE_DATE_LABEL`, `TRANSPARENCY_TITLE`,
`VAT_CONTAINED_LABEL`, etc.

## Dónde va cada dato

La RG 1415 (Anexo II, apartado B) ubica los datos:

- Arriba a la izquierda: nombre de fantasía, razón social, domicilio y
  condición frente al IVA.
- Arriba en el centro, destacada: la letra y, debajo, `Código Nº` con el
  código. `letterLegend`, junto a la letra.
- Arriba a la derecha: número, fecha, CUIT, ingresos brutos e inicio de
  actividades. Estos datos y los de la izquierda van en un recuadro de al menos
  7 × 3 cm.
- Después: el receptor, las condiciones de venta y las líneas, con el IVA por
  alícuota a continuación en la clase A.
- Abajo a la izquierda: el bloque de transparencia fiscal.
- Abajo a la derecha: el CAE y `Fecha Vto.:` con su vencimiento, en letra de
  12 puntos o más (`CAE_DUE_DATE_MIN_FONT_SIZE_PT`).
- El QR, en el frente, sin tapar ningún dato obligatorio. No uses el logo de
  ARCA.

Los montos están en centavos: el formato de moneda queda en tu plantilla. Que
un contador revise el resultado con tus casos reales.
