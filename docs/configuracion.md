# Configuración

[Inicio](../README.md) · [Facturación](facturacion.md) · [Referencia](referencia.md)

## Antes de usar la biblioteca

Homologación y producción se preparan por separado; no son intercambiables.
Para cada ambiente necesitás:

1. **Una clave privada y su certificado**, emitido por ARCA a partir de un CSR.
   El certificado no reemplaza a la clave.
2. **El certificado asociado a `wsfe`** para la CUIT que va a representar.
3. **Un punto de venta** habilitado para facturación por web services.
4. **Tus datos fiscales**: condición de IVA, régimen y comprobantes habilitados.
5. **Un directorio privado y persistente** fuera del webroot, compartido por
   todos los workers.

En homologación, certificados y autorizaciones se gestionan con **WSASS**. En
producción, con **Administración de Certificados Digitales** y
**Administrador de Relaciones de Clave Fiscal**. Ver
[Web Services](https://www.arca.gob.ar/ws/) y
[Factura electrónica](https://www.arca.gob.ar/fe/).

### Padrón (opcional)

Solo si querés consultar datos del receptor. Requieren habilitaciones aparte:

| Método | Servicio |
|---|---|
| `taxpayer()` | `ws_sr_constancia_inscripcion` (A5) |
| `cuitsByDni()` | `ws_sr_padron_a13` (A13) |

El Padrón de homologación no contiene la mayoría de las CUIT reales.

## Crear el cliente

```php
use Afiphp\{Arca, Configuration, Credentials, Environment};
use Afiphp\Billing\VatCondition;
use Afiphp\Storage\FileStorage;

$arca = new Arca(new Configuration(
    cuit: '20111111112',
    vatCondition: VatCondition::RESPONSABLE_INSCRIPTO,
    credentials: new Credentials(
        certificate: $certificatePem,
        privateKey: $privateKeyPem,
        // passphrase: '...', si la clave está cifrada.
    ),
    storage: new FileStorage('/privado/arca'),
    environment: Environment::HOMOLOGATION, // Predeterminado.
));
```

- Construir el cliente no contacta ARCA; `FileStorage` sí crea directorios.
- La configuración es inmutable. Para cambiar ambiente, credenciales u opciones,
  creá otro cliente. Producción nunca se elige automáticamente.
- La condición fiscal la **afirma** la aplicación; la biblioteca no consulta el
  Padrón por su cuenta. Con ella deriva la clase A, B o C.

## Credenciales

`Credentials` recibe **contenido PEM, no rutas**: un certificado y una clave.
Verifica localmente que la clave corresponda al certificado y, si no, lanza
`InvalidArgumentException`. No valida vigencia, cadena de confianza ni
habilitaciones: eso lo hace ARCA.

- No guarda la contraseña ni escribe la clave en disco. No se puede serializar.
- `fingerprint` es el SHA-256 del certificado en DER.
- Los tickets de autenticación se piden y renuevan solos.
- Leé los PEM desde archivos privados o un gestor de secretos; nunca desde el
  repositorio ni el webroot. No registres la configuración en logs.
- No hay soporte para HSM ni firmantes remotos.

**Rotar el certificado:** creá otro cliente con las credenciales nuevas y **el
mismo almacenamiento**. Las sesiones se separan por huella.

## Red y diagnóstico

```php
use Afiphp\NetworkOptions;

$network = new NetworkOptions(
    connectionTimeout: 10,   // Segundos.
    readTimeout: 60,         // Por lectura, no total.
    saveSoapMessages: false, // SOAP redactado; puede tener datos personales.
    lockTimeout: 10,         // Espera del bloqueo de autenticación.
    allowLegacyTls: false,   // DH antiguo (SECLEVEL=1); sigue verificando CA y host.
);
// new Configuration(..., network: $network)
```

No hay reintentos automáticos. El transporte cambia temporalmente
`default_socket_timeout`, así que usá un runtime síncrono.

## Migración

La biblioteca no migra registros automáticamente. Para incorporarla a un
sistema que ya emite:

1. Detené todos los emisores.
2. Respaldá las emisiones y sesiones existentes.
3. Verificá que los registros sean compatibles.
4. Reanudá cuando todos los emisores compartan el mismo almacenamiento.

No cambies de directorio ni muevas registros como si fueran caché. Ver
[almacenamiento](almacenamiento.md).
