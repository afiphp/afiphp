# Referencia de la API

[Inicio](../README.md) · [Facturación](facturacion.md) · [Errores y reintentos](conciliacion.md)

Todos los objetos son inmutables. Los importes son `int` en centavos y las
fechas, `DateTimeImmutable`. Salvo que se indique otro, los tipos están en
`Afiphp\Billing`.

## Arca

`new Afiphp\Arca(Configuration $configuration)`

| Método | Devuelve | Hace |
|---|---|---|
| `preview(Invoice $invoice)` | `Preview` | Calcula clase e importes. Sin red. |
| `issue(Invoice $invoice, ?int $previousNumber = null)` | `IssueResult` | Emite. Ver [errores y reintentos](conciliacion.md). |
| `fullCreditNote(InvoiceReference $original, DateTimeImmutable $date, ?int $salesPoint = null)` | `Invoice` | Arma la [nota de crédito total](facturacion.md#nota-de-crédito-total) consultando el original. No emite. |
| `voucher(InvoiceClass $class, int $salesPoint, int $number, InvoiceKind $kind = INVOICE)` | `?IssueResult` | [Consulta](facturacion.md#consultar-comprobantes) un comprobante autorizado. `null` si no existe. |
| `lastNumber(int $salesPoint, InvoiceClass $class, InvoiceKind $kind = INVOICE)` | `int` | Último número autorizado de la serie; `0` si no hay. |
| `check(?int $salesPoint = null)` | `void` | Prueba si se puede facturar ahora. Lanza `ArcaUnavailable` o `ConfigurationError`. |
| `salesPoints()` | `list<Afiphp\SalesPoint>` | Puntos de venta que ARCA lista para web services. |
| `status()` | `Afiphp\ServiceStatus` | Estado de WSFE (`FEDummy`). No usa el certificado. |
| `dollarRate(?DateTimeImmutable $date = null)` | `string` | Cotización publicada por ARCA. |
| `taxpayer(string $cuit)` | `?Taxpayer` | Consulta el Padrón A5. |
| `cuitsByDni(string $dni)` | `list<string>` | Consulta el Padrón A13. |

`status()` responde si ARCA funciona; `check()`, si este emisor puede facturar:
un servicio disponible todavía puede rechazar el certificado o la CUIT. Ver
[verificar la instalación](configuracion.md#verificar-la-instalación).

`CERTIFICATE_WARNING_DAYS` (30) es desde cuándo `check()` advierte que vence
el certificado.

## Configuración

### Configuration (`Afiphp`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `cuit` | `string` | Obligatorio. 11 dígitos, verificador válido. |
| `vatCondition` | `VatCondition` | Obligatorio. No admite `CONSUMIDOR_FINAL`. |
| `credentials` | `Credentials` | Obligatorio. |
| `storage` | `Afiphp\Storage\Storage` | Obligatorio. |
| `environment` | `Environment` | `HOMOLOGATION` |
| `network` | `NetworkOptions` | `new NetworkOptions()` |
| `logger` | `Psr\Log\LoggerInterface` | `new NullLogger()`. Ver [logs](configuracion.md#logs). |

### Credentials (`Afiphp`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `certificate` | `string` | Obligatorio. Contenido PEM. |
| `privateKey` | `string` | Obligatorio. Contenido PEM. |
| `passphrase` | `string` | `''` |

Expone `fingerprint` (SHA-256 del certificado DER), `validFrom` y `expiresAt`
(`DateTimeImmutable`), e `isValidAt(?DateTimeImmutable $at = null): bool`.

### FileStorage (`Afiphp\Storage`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `directory` | `string` | Obligatorio. |

### NetworkOptions (`Afiphp`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `connectionTimeout` | `int` | `10` segundos. |
| `readTimeout` | `int` | `60` segundos. |
| `saveSoapMessages` | `bool` | `false` |
| `lockTimeout` | `int` | `10` segundos de espera del bloqueo de login. |
| `allowLegacyTls` | `bool` | `false` |
| `salesPointLockTimeout` | `int` | `120` segundos de espera a otra emisión en el mismo punto de venta. |

Ver [configuración](configuracion.md) y [almacenamiento](almacenamiento.md).

## Comprobante

### Invoice

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `salesPoint` | `int` | Obligatorio. De 1 a 99999. |
| `receiver` | `Receiver` | Obligatorio. |
| `items` | `list<Item>` | Obligatorio. No vacía. |
| `date` | `DateTimeImmutable` | Obligatorio. |
| `service` | `?ServicePeriod` | `null` (productos). |
| `currency` | `?Currency` | `null` (pesos). |
| `kind` | `InvoiceKind` | `INVOICE` |
| `original` | `?InvoiceReference` | `null` |
| `otherTaxes` | `list<OtherTax>` | `[]` |
| `period` | `?AssociatedPeriod` | `null` |

Una factura no admite `original` ni `period`; una nota requiere uno de los dos.

### Receiver

| Fábrica | Documento |
|---|---|
| `Receiver::business(string $cuit, VatCondition $vatCondition)` | CUIT |
| `Receiver::finalConsumerWithDni(string $dni)` | DNI |
| `Receiver::finalConsumerWithCuil(string $cuil)` | CUIL |
| `Receiver::finalConsumerWithCuit(string $cuit)` | CUIT |
| `Receiver::fromArcaDocument(int $documentType, string $number, VatCondition $vatCondition)` | Código ARCA: 80 (CUIT), 86 (CUIL) o 96 (DNI). Si no es consumidor final, exige CUIT. |

Expone `document`, `documentType` (`DocumentType`) y `vatCondition`.

### Item

| Fábrica | Uso |
|---|---|
| `Item::amount(int $amount)` | Monotributista o exento. |
| `Item::amount(int $amount, VatRate $vat)` | Responsable inscripto, IVA incluido. |
| `Item::netAmount(int $amount, VatRate $vat)` | Responsable inscripto, sin IVA. |

Expone `amount`, `vat` (`?VatRate`) e `includesVat`.

### Otros componentes

| Constructor | Parámetros |
|---|---|
| `new ServicePeriod(from, to, paymentDue, includesProducts: false)` | Fechas y `bool`. |
| `new AssociatedPeriod(from, to)` | Fechas. Solo notas sin `original`. |
| `new InvoiceReference(class, salesPoint, number, date, kind: InvoiceKind::INVOICE)` | Preferí `$result->invoiceReference()`. |
| `Currency::pesos()` | — |
| `Currency::dollars(string $exchangeRate, bool $paidInDollars)` | Cotización decimal, hasta 4 enteros y 6 decimales. |
| `new OtherTax(type, description, base, percentage)` | `OtherTaxType`, `string` (≤ 80 bytes), `int`, `string` (0 a 999.99). Expone `amount`. |

## Resultados

### IssueResult

Un comprobante autorizado: el que devuelve `issue()` o el que consulta
`voucher()`.

| Campo | Tipo | Contenido |
|---|---|---|
| `class` | `InvoiceClass` | A, B o C. |
| `kind` | `InvoiceKind` | |
| `salesPoint` | `int` | |
| `number` | `int` | |
| `date` | `DateTimeImmutable` | La de la autorización. Con `previousNumber`, puede ser anterior a la de la factura. |
| `totals` | `Preview` | |
| `authorization` | `Authorization` | CAE y vencimiento. |
| `observations` | `list<string>` | Revisarlas aunque se haya autorizado. |
| `qr` | `string` | URL del QR (RG 4892). |
| `receiver` | `?Receiver` | `null` solo en un comprobante consultado con receptor sin identificar. |
| `currency` | `Currency` | |

`invoiceReference()` devuelve la referencia para asociar una nota.

### QrCode

Para imprimir más tarde un comprobante ya autorizado, sin el `IssueResult`:

```php
use Afiphp\Billing\QrCode;

$url = QrCode::url(
    issuerCuit: '20111111112',
    voucher: new InvoiceReference(InvoiceClass::B, 2, 50, $fecha),
    total: 18150, // Centavos.
    cae: '71372966559657',
    receiver: null, // Opcional: omitilo si los datos del cliente pudieron cambiar.
    currency: null, // Pesos.
);
```

### Authorization

| Campo | Tipo |
|---|---|
| `cae` | `string` (14 dígitos; no convertir a número) |
| `expiresOn` | `DateTimeImmutable` |

### Preview

| Campo | Tipo | Contenido |
|---|---|---|
| `class` | `InvoiceClass` | |
| `net` | `int` | Neto gravado (en C, el importe de los ítems). |
| `vat` | `int` | IVA. |
| `exempt` | `int` | Exento. |
| `untaxed` | `int` | No gravado. |
| `otherTaxes` | `int` | Otros tributos. |
| `total` | `int` | Suma de todo lo anterior. |
| `vatByRate` | `list<VatSubtotal>` | `rate`, `base` y `amount` por alícuota. Vacío en C. |

### SalesPoint (`Afiphp`)

Campos `number`, `emissionType` (`string`, como lo informa ARCA), `blocked`
(`bool`) y `deregisteredOn` (`?DateTimeImmutable`). `isActive()` es `true` si
no está bloqueado ni dado de baja.

### ServiceStatus (`Afiphp`)

Campos `appServer`, `dbServer` y `authServer` (`string`, tal como los informa
ARCA). `isAvailable()` es `true` cuando los tres dicen `OK`.

### Taxpayer

Campos: `cuit`, `name`, `personType`, `vatCondition` (`?VatCondition`),
`address`, `city`, `postalCode`, `province` (todos `?string` salvo `cuit`) y
`errors` (`list<string>`). `receiver()` crea el `Receiver` o lanza
`LogicException` si `vatCondition` es `null`.

## Impresión

En `Afiphp\Printing`. Ver [comprobante impreso](impresion.md).

`PrintableInvoice::build(Invoice $invoice, IssueResult $result, IssuerDetails $issuer, array $lines, string $saleConditions, ?ReceiverDetails $receiver = null, array $remitos = [])`

| Constructor | Parámetros |
|---|---|
| `new IssuerDetails(cuit, vatCondition, legalName, address, grossIncome, activitiesStart, tradeName: null, paymentToInformedCbu: false)` | `activitiesStart` es `?DateTimeImmutable`, sin valor por defecto. |
| `new PrintedLine(description, quantity: null, unitPrice: null, discount: 0, code: null, unit: null)` | `quantity` es un decimal como `string`; `unitPrice` y `discount`, centavos. |
| `new ReceiverDetails(name: null, address: null)` | |

`PrintableInvoice` expone `letter`, `code`, `title`, `letterLegend`, `number`,
`date`, `issuer`, `issuerConditionLegend`, `receiverConditionLegend`,
`receiverName`, `receiverAddress`, `receiverDocumentLabel`,
`receiverDocument`, `saleConditions`, `remitos`, `service`, `currency`,
`lines` (`list<PrintableLine>`), `subtotal`, `exempt`, `untaxed`,
`vatByRate`, `otherTaxes`, `total`, `transparencyVat`,
`transparencyOtherTaxes`, `cae`, `caeDueDate`, `qr` y `legends`.

`PrintableLine` expone `description`, `quantity`, `unitPrice`, `discount`,
`amount`, `vat` (solo en A), `code` y `unit`.

## Línea de comandos

`vendor/bin/afiphp init` y `vendor/bin/afiphp check`. Ver
[configuración](configuracion.md#con-el-comando-afiphp).

## Enums

| Enum | Casos |
|---|---|
| `Afiphp\Environment` | `HOMOLOGATION`, `PRODUCTION` |
| `VatCondition` | `RESPONSABLE_INSCRIPTO`, `MONOTRIBUTISTA`, `MONOTRIBUTISTA_SOCIAL`, `MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO`, `EXENTO`, `NO_ALCANZADO`, `CONSUMIDOR_FINAL` (solo receptor) |
| `VatRate` | `ZERO`, `REDUCED_2_5`, `REDUCED_5`, `REDUCED_10_5`, `STANDARD_21`, `INCREASED_27`, `EXEMPT`, `UNTAXED` |
| `InvoiceKind` | `INVOICE`, `CREDIT_NOTE`, `DEBIT_NOTE` |
| `InvoiceClass` | `A`, `B`, `C` |
| `DocumentType` | `CUIT`, `CUIL`, `DNI` |
| `OtherTaxType` | `NATIONAL`, `PROVINCIAL`, `MUNICIPAL`, `INTERNAL`, `GROSS_INCOME`, `VAT_PERCEPTION`, `GROSS_INCOME_PERCEPTION`, `MUNICIPAL_PERCEPTION`, `OTHER_PERCEPTION`, `OTHER` |

Si tu sistema guarda los códigos de ARCA, convertilos con
`VatCondition::fromArcaCode(int $code)` (`CondicionIVAReceptorId`) y
`VatRate::fromArcaCode(int $code)` (`Id` de la alícuota). Un código desconocido
lanza `InvalidArgumentException`. Exento y no gravado no tienen código.

## Excepciones

Las propias están en `Afiphp\Exceptions`.

| Excepción | Indica |
|---|---|
| `InvoiceRejected` | ARCA rechazó la factura. `observations`, `number`. |
| `UnconfirmedIssue` | Se perdió la respuesta y no se pudo confirmar. `number`; la falla original en `getPrevious()`. |
| `ArcaUnavailable` | ARCA no respondió o falló internamente. `sent` es `false` si el pedido no salió. |
| `ConfigurationError` | Certificado, habilitación del servicio, reloj, TLS o un ticket tomado por otro almacenamiento. |
| `WsException` | Otro error informado por ARCA. `getErrors()`, `getResponse()`. |
| `SoapException` | Otra falla SOAP, como un pedido mal formado. `getFaultCode()`. |
| `InvalidResponseException` | Respuesta inválida. `getResponse()`, `getOperation()`. |
| `InvalidArgumentException` | Datos inválidos o PEM incorrecto. Se lanza antes de contactar ARCA. |
| `UnexpectedValueException` | `voucher()` encontró un comprobante que afiphp no modela (otra moneda, CAEA). |
| `LockTimeout` | Otro worker retuvo el bloqueo de emisión o de login más que el tiempo de espera. No se envió nada. |

Ver [qué hacer con cada una](conciliacion.md#qué-puede-pasar-al-emitir).
