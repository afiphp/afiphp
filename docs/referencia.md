# Referencia de la API

[Inicio](../README.md) · [Facturación](facturacion.md) · [Conciliación](conciliacion.md)

Todos los objetos son inmutables. Los importes son `int` en centavos y las
fechas, `DateTimeImmutable`. Salvo que se indique otro, los tipos están en
`Afiphp\Billing`.

## Arca

`new Afiphp\Arca(Configuration $configuration)`

| Método | Devuelve | Hace |
|---|---|---|
| `preview(Invoice $invoice)` | `Preview` | Calcula clase e importes. Sin red. |
| `issue(Invoice $invoice, ?string $operation = null)` | `IssueResult` | Emite. Con un ID repetido devuelve lo guardado. |
| `recover(string $operation)` | `IssueResult` | Consulta una operación pendiente. No emite. |
| `retry(string $operation)` | `IssueResult` | Reenvía el mismo pedido si ARCA confirma que no existe. |
| `fullCreditNote(string $originalOperation, ?DateTimeImmutable $date = null)` | `Invoice` | Arma una nota de crédito total. No emite. |
| `creditNote(string $originalOperation, array $items, array $otherTaxes = [], ?DateTimeImmutable $date = null)` | `Invoice` | Arma una nota de crédito parcial. No emite. |
| `debitNote(string $originalOperation, array $items, array $otherTaxes = [], ?DateTimeImmutable $date = null)` | `Invoice` | Arma una nota de débito. No emite. |
| `status()` | `Afiphp\ServiceStatus` | Estado de WSFE (`FEDummy`). No inicia sesión. |
| `lastNumber(InvoiceClass $class, InvoiceKind $kind, int $salesPoint)` | `int` | Último número autorizado de la serie. No reserva. |
| `dollarRate(?DateTimeImmutable $date = null)` | `string` | Cotización publicada por ARCA. |
| `taxpayer(string $cuit)` | `?Taxpayer` | Consulta el Padrón A5. |
| `cuitsByDni(string $dni)` | `list<string>` | Consulta el Padrón A13. |

`recover()` y `retry()` lanzan `InvalidArgumentException` si el ID no existe.

`status()` no usa el certificado: un servicio disponible todavía puede rechazar
el login o la CUIT. `lastNumber()` sí inicia sesión, así que también prueba el
certificado y la autorización de `wsfe`.

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

### Credentials (`Afiphp`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `certificate` | `string` | Obligatorio. Contenido PEM. |
| `privateKey` | `string` | Obligatorio. Contenido PEM. |
| `passphrase` | `string` | `''` |

Expone `fingerprint` (SHA-256 del certificado DER).

### FileStorage (`Afiphp\Storage`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `directory` | `string` | Obligatorio. |
| `lockTimeout` | `int` | `120` segundos. |

### NetworkOptions (`Afiphp`)

| Parámetro | Tipo | Predeterminado |
|---|---|---|
| `connectionTimeout` | `int` | `10` segundos. |
| `readTimeout` | `int` | `60` segundos. |
| `saveSoapMessages` | `bool` | `false` |
| `lockTimeout` | `int` | `10` segundos. |
| `allowLegacyTls` | `bool` | `false` |

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

| Campo | Tipo | Contenido |
|---|---|---|
| `outcome` | `Outcome` | Ver [estados](conciliacion.md#qué-hacer-con-cada-estado). |
| `operation` | `string` | ID de la operación. |
| `class` | `InvoiceClass` | A, B o C. |
| `kind` | `InvoiceKind` | |
| `salesPoint` | `int` | |
| `number` | `?int` | `null` si `BLOCKED`. Tener número no implica autorización. |
| `date` | `DateTimeImmutable` | |
| `totals` | `Preview` | |
| `authorization` | `?Authorization` | Solo en `AUTHORIZED`. |
| `observations` | `list<string>` | Revisarlas también si se autorizó. |
| `reason` | `?string` | Detalle técnico. |
| `blockedBy` | `?string` | Operación que bloquea, si `BLOCKED`. |
| `reconciled` | `bool` | `true` si se confirmó con una consulta posterior. |
| `qr` | `?string` | URL del QR (RG 4892), solo en `AUTHORIZED`. |

`invoiceReference()` devuelve la referencia para asociar una nota; lanza
`LogicException` si no está autorizado.

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

### ServiceStatus (`Afiphp`)

Campos `appServer`, `dbServer` y `authServer` (`string`, tal como los informa
ARCA). `isAvailable()` es `true` cuando los tres dicen `OK`.

### Taxpayer

Campos: `cuit`, `name`, `personType`, `vatCondition` (`?VatCondition`),
`address`, `city`, `postalCode`, `province` (todos `?string` salvo `cuit`) y
`errors` (`list<string>`). `receiver()` crea el `Receiver` o lanza
`LogicException` si `vatCondition` es `null`.

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
| `Outcome` | `AUTHORIZED`, `REJECTED`, `INDETERMINATE`, `CONFLICT`, `BLOCKED` |

## Excepciones

Las propias están en `Afiphp\Exceptions`.

| Excepción | Indica |
|---|---|
| `InvalidArgumentException` | Datos inválidos, fecha fuera de ventana, PEM incorrecto u operación desconocida. |
| `IdempotencyConflict` | El ID ya existe con otros datos. |
| `IssuancePersistenceException` | Hubo resultado (`result`) pero no se pudo guardar. |
| `SoapException` | Fallo de transporte. `getFaultCode()`, `getPrevious()`. |
| `ConnectionException` | `SoapException` sin respuesta de ARCA: sin conexión, timeout o página de error HTTP. |
| `WsException` | Error del servicio. `getErrors()`, `getResponse()`. |
| `ServiceErrorException` | `WsException` con un error interno de WSFE (500, 501 o 502). |
| `ServiceUnavailable` | Interfaz de `ConnectionException` y `ServiceErrorException`: ARCA no pudo procesar el pedido. |
| `InvalidResponseException` | Respuesta inválida. `getResponse()`, `getOperation()`. |
| `LogicException` | Uso no permitido del objeto. |
| `RuntimeException` | Fallos de almacenamiento, bloqueos o secuencia. |

Ninguna excepción significa “no se emitió”. Ver
[manejo de excepciones](conciliacion.md#excepciones).
