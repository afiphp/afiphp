# AFIPHP

Biblioteca PHP para emitir facturas, notas de crédito y notas de débito
**A, B y C** con ARCA (ex AFIP), en pesos o dólares. Calcula IVA y tributos,
administra la autenticación y recupera emisiones cuyo resultado no se pudo
confirmar.

## Instalación

```bash
composer require afiphp/afiphp
```

Requiere PHP **8.3+ de 64 bits** con `soap`, `openssl`, `simplexml` y `json`.

## Uso básico

Necesitás un certificado con su clave privada, el servicio `wsfe` habilitado
para tu CUIT y un punto de venta para web services. Si no los tenés, seguí la
[guía de configuración](docs/configuracion.md).

Este ejemplo emite, **en producción**, una factura B de $150,00 + IVA a un
consumidor final, desde un responsable inscripto. Para monotributo, usá la
[factura C](docs/facturacion.md#clase-del-comprobante).

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Afiphp\{Arca, Configuration, Credentials, Environment};
use Afiphp\Billing\{Invoice, Item, Receiver, VatCondition, VatRate};
use Afiphp\Storage\FileStorage;

$arca = new Arca(new Configuration(
    cuit: '20111111112', // Tu CUIT.
    vatCondition: VatCondition::RESPONSABLE_INSCRIPTO,
    credentials: new Credentials(
        certificate: file_get_contents('/privado/certificado.crt'),
        privateKey: file_get_contents('/privado/clave.key'),
    ),
    storage: new FileStorage('/privado/arca'),
    environment: Environment::PRODUCTION,
));

$invoice = new Invoice(
    salesPoint: 2, // Tu punto de venta.
    receiver: Receiver::finalConsumerWithDni('12345678'),
    items: [Item::amount(18150, VatRate::STANDARD_21)], // Importes en centavos, IVA incluido.
    date: new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
);

$result = $arca->issue($invoice);

echo "Número: {$result->number}\n";
echo "CAE: {$result->authorization->cae}\n";
echo "Total: {$result->totals->total} centavos\n"; // 18150 = $181,50.
```

`issue()` devuelve solo facturas autorizadas. Lo demás es una excepción:

| Excepción | Qué hacer |
|---|---|
| `InvoiceRejected` | Corregir según `$e->observations` y emitir de nuevo. |
| `UnconfirmedIssue` | Guardar `$e->number` con la factura y pasarlo como `previousNumber` en el próximo intento. |
| `ArcaUnavailable` | Esperar y repetir. |
| `ConfigurationError` | Revisar certificado, habilitaciones, reloj o TLS. |
| `LockTimeout` | Otra emisión ocupaba el punto de venta; no se envió nada. Repetir. |

**Si se pierde la respuesta de ARCA, la factura puede existir.** Por eso, ante
`UnconfirmedIssue`, no emitas de nuevo sin el número: ver
[errores y reintentos](docs/conciliacion.md).

`$arca->preview($invoice)` calcula importes sin contactar ARCA.

Para preparar el certificado y probar la instalación, el paquete trae un
comando: `vendor/bin/afiphp init` y `vendor/bin/afiphp check` (ver
[configuración](docs/configuracion.md#con-el-comando-afiphp)).

## Documentación

| Quiero… | Guía |
|---|---|
| Preparar certificados, servicios y ambientes | [Configuración](docs/configuracion.md) |
| Emitir C, IVA incluido, servicios, dólares, tributos o notas; consultar comprobantes | [Facturación](docs/facturacion.md) |
| Armar el comprobante impreso | [Comprobante impreso](docs/impresion.md) |
| Revisar todo antes de emitir de verdad | [Pasar a producción](docs/produccion.md) |
| Manejar errores, respuestas perdidas y reintentos | [Errores y reintentos](docs/conciliacion.md) |
| Guardar las sesiones de ARCA o implementar otro backend | [Almacenamiento](docs/almacenamiento.md) |
| Consultar métodos, parámetros y errores | [Referencia](docs/referencia.md) |
| Desarrollar y ejecutar las pruebas | [Contribuir](CONTRIBUTING.md) |

## Alcance

- La aplicación afirma las condiciones fiscales y decide qué impuestos aplicar;
  ARCA verifica las habilitaciones. Las pruebas no certifican cumplimiento
  fiscal: hace falta revisión contable y homologación.
- No incluye exportación, FCE MiPyME, CAEA, comprobantes M ni lotes. No genera
  PDF: arma el [modelo del comprobante impreso](docs/impresion.md) para tu
  plantilla.
- El namespace `Internal` no es API pública.
- Si ya tenés un sistema emitiendo, leé [cómo migrar](docs/configuracion.md#migración).
