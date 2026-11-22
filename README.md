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
use Afiphp\Billing\{Invoice, Item, Outcome, Receiver, VatCondition, VatRate};
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

$result = $arca->issue($invoice, 'venta:123');

if ($result->outcome === Outcome::AUTHORIZED) {
    echo "Número: {$result->number}\n";
    echo "CAE: {$result->authorization->cae}\n";
    echo "Total: {$result->totals->total} centavos\n"; // 18150 = $181,50.
}
```

Tres reglas:

1. **Guardá la factura y su ID (`venta:123`) antes de emitir.** Repetir
   `issue()` con el mismo ID y los mismos datos devuelve el resultado guardado,
   sin emitir de nuevo.
2. **Un timeout o una excepción no autorizan a emitir con otro ID.** Tratá cada
   [estado del resultado](docs/conciliacion.md#qué-hacer-con-cada-estado).
3. **El almacenamiento no es caché.** No lo borres ni lo restaures para
   desbloquear una serie.

`$arca->preview($invoice)` calcula importes sin contactar ARCA.

## Documentación

| Quiero… | Guía |
|---|---|
| Preparar certificados, servicios y ambientes | [Configuración](docs/configuracion.md) |
| Emitir C, IVA incluido, servicios, dólares, tributos o notas | [Facturación](docs/facturacion.md) |
| Resolver pendientes, bloqueos y reintentos | [Conciliación](docs/conciliacion.md) |
| Desplegar, respaldar o implementar otro backend | [Almacenamiento](docs/almacenamiento.md) |
| Consultar métodos, parámetros y errores | [Referencia](docs/referencia.md) |
| Desarrollar y ejecutar las pruebas | [Contribuir](CONTRIBUTING.md) |

## Alcance

- La aplicación afirma las condiciones fiscales y decide qué impuestos aplicar;
  ARCA verifica las habilitaciones. Las pruebas no certifican cumplimiento
  fiscal: hace falta revisión contable y homologación.
- No incluye exportación, FCE MiPyME, CAEA, comprobantes M ni lotes. No genera
  PDF: devuelve el CAE y la URL del QR.
- El namespace `Internal` no es API pública.
- Si ya tenés un sistema emitiendo, leé [cómo migrar](docs/configuracion.md#migración).
