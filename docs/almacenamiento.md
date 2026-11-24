# Almacenamiento

[Inicio](../README.md) · [Configuración](configuracion.md) · [Errores y reintentos](conciliacion.md)

El almacenamiento guarda las **sesiones de ARCA** (tickets de WSAA), coordina
las **emisiones por punto de venta** y, si los activás, guarda diagnósticos
SOAP. No guarda nada sobre las facturas: el número de un intento sin confirmar
lo guarda tu aplicación (ver [errores y reintentos](conciliacion.md)).

Compartirlo entre todos los workers importa: ARCA rechaza un login nuevo
mientras el anterior siga vigente (`coe.alreadyAuthenticated`), y dos workers
con almacenamientos distintos pueden pedir el mismo número.

## FileStorage

```php
use Afiphp\Storage\FileStorage;

$storage = new FileStorage('/privado/arca');
```

Usá un directorio privado, persistente y fuera del webroot. Adentro:

| Directorio | Contiene |
|---|---|
| `tickets/` | Sesiones de autenticación, cifradas. |
| `locks/` | Bloqueos de emisión por punto de venta. Vacíos. |
| `diagnostics/` | SOAP redactado, si `saveSoapMessages` está activo. Puede tener datos personales. |

- Crea directorios `0700` y archivos `0600`. Rechaza directorios con permisos
  de grupo u otros, sin corregirlos.
- Sirve para un host con disco local. Para varios hosts que emiten con la misma
  CUIT, implementá otro backend.
- Borrar `tickets/` no rompe nada, pero el próximo login puede fallar con
  `coe.alreadyAuthenticated` hasta que venza la sesión anterior.

## Otros backends

Implementá `Afiphp\Storage\Storage` y pasalo como `storage:` en `Configuration`.

| Método | Debe |
|---|---|
| `readTicket(string $key): ?string` | Devolver el ticket guardado o `null` si no existe. |
| `writeTicket(string $key, string $ticket): void` | Guardarlo de forma atómica y privada. |
| `withTicketLock(string $key, int $timeout, callable $callback): mixed` | Excluir otros logins durante todo el callback. Lanzar `LockTimeout` si no lo obtiene en `$timeout` segundos. |
| `withSalesPointLock(string $key, int $timeout, callable $callback): mixed` | Excluir otras emisiones con la misma clave durante todo el callback, que espera la respuesta de ARCA. Lanzar `LockTimeout` si no lo obtiene en `$timeout` segundos. |
| `writeDiagnostic(string $key, string $xml): void` | Guardar el XML redactado, o no hacer nada. |

Los bloqueos no pueden vencer mientras el callback corre: un bloqueo con
vencimiento (un *lease* de Redis, por ejemplo) tiene que durar más que una
emisión completa, que puede sumar varias veces `connectionTimeout` +
`readTimeout`. Las claves de los dos bloqueos pueden coincidir: no deben
excluirse entre sí.
