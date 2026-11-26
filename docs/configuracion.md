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

### Con el comando `afiphp`

El paquete instala `vendor/bin/afiphp`, que resuelve la parte manual. Ninguno
de sus comandos emite comprobantes ni imprime claves o tickets.

```bash
vendor/bin/afiphp init --cuit 20111111112                    # homologación
vendor/bin/afiphp init --cuit 20111111112 --env production
```

`init` escribe `arca-homologation.key` (RSA 2048, `0600`) y
`arca-homologation.csr`, con el subject que pide ARCA (`C=AR`, `O`, `CN` y
`serialNumber=CUIT …`), e imprime los pasos exactos en WSASS o en el
Administrador de Relaciones. Si hay un `.gitignore` en el directorio, le agrega
`arca-*.key` y `arca-*.crt`. No pisa archivos existentes. Opciones: `--name`
(alias del certificado, `afiphp` por defecto), `--org` (la CUIT por defecto) y
`--dir`.

Con el certificado guardado como `arca-homologation.crt`:

```bash
vendor/bin/afiphp check --cuit 20111111112 --sales-point 2
```

`check` prueba cada capa en orden (certificado y vencimiento, servicio de
ARCA, login y habilitación de `wsfe`, puntos de venta) y, si una falla, dice
cuál y qué hacer. Acepta `--cert`, `--key`, `--env` y `--storage`, o las
variables `ARCA_CUIT`, `ARCA_CERT`, `ARCA_KEY`, `ARCA_KEY_PASSPHRASE`,
`ARCA_ENVIRONMENT`, `ARCA_SALES_POINT` y `ARCA_STORAGE`. Sin `--storage`,
guarda la sesión de ARCA en un directorio temporal privado: si después la
aplicación usa otro almacenamiento, su primer login puede fallar con
`coe.alreadyAuthenticated` por unos minutos. Pasale el `--storage` de la
aplicación para evitarlo.

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
`InvalidArgumentException`. No valida cadena de confianza ni habilitaciones:
eso lo hace ARCA.

El certificado vence (los de ARCA duran unos dos años). `expiresAt` dice
cuándo e `isValidAt()` si está vigente; `check()` lanza `ConfigurationError`
si venció y registra una advertencia en el logger los últimos 30 días.

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
new Configuration(..., timeout: 60); // Segundos de espera de cada respuesta de ARCA.
```

Un `timeout` corto deja más emisiones sin confirmar: si se corta mientras ARCA
autoriza, hay que resolverla con `retryIssue()`. No hay reintentos
automáticos. El transporte cambia temporalmente `default_socket_timeout`, así
que usá un runtime síncrono.

Una emisión espera hasta 120 segundos a que termine otra en el mismo punto de
venta: ver [emisiones simultáneas](conciliacion.md#emisiones-simultáneas).

### Logs

`Configuration` acepta cualquier logger PSR-3 (Monolog, el de Laravel o
Symfony):

```php
new Configuration(..., logger: $logger);
```

| Nivel | Qué registra |
|---|---|
| `debug` | Cada llamada a ARCA: servicio, operación, resultado y duración. Sin el contenido. |
| `info` | Logins de WSAA y comprobantes autorizados (número y CAE). |
| `warning` | Comprobantes rechazados, con las observaciones de ARCA; emisiones que no salieron porque ARCA no respondió o por el bloqueo de otra emisión; un certificado por vencer. |
| `error` | Emisiones sin confirmar, con el número que hay que guardar; números que hay que conciliar a mano (`NeedsReconciliation`); configuración rechazada por ARCA (certificado, habilitación, reloj o TLS). |

Nunca registra certificados, claves, tickets ni datos del receptor.

## Verificar la instalación

```php
$arca->check(2); // Lanza ArcaUnavailable o ConfigurationError si no se puede facturar.
```

Prueba la vigencia del certificado, el servicio, el login y la habilitación de
`wsfe` para la CUIT, sin emitir nada. Con un punto de venta, además verifica que
ARCA lo liste para web services y que no esté bloqueado ni dado de baja. La
homologación suele no listar ninguno y acepta cualquiera; producción, no.

`$arca->salesPoints()` lista los puntos de venta que ARCA informa, con su tipo
de emisión, si están bloqueados y su fecha de baja.

## Migración

afiphp no guarda nada sobre las facturas: la numeración la lleva ARCA. Podés
empezar a usarla en un sistema que ya emite sin migrar datos. Si el sistema
anterior dejó una factura sin confirmar, pasá su número a `retryIssue()`. Con
[`voucher()`](facturacion.md#consultar-comprobantes) podés leer lo que ese
sistema emitió, y con `fullCreditNote()`, anularlo.

Antes de emitir el primer comprobante real, repasá
[Pasar a producción](produccion.md).
