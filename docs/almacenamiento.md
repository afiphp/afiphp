# Almacenamiento

[Inicio](../README.md) · [Configuración](configuracion.md) · [Conciliación](conciliacion.md)

El almacenamiento guarda las sesiones de ARCA y el **registro de emisiones**.
Ese registro es lo que evita facturar dos veces: **no es caché**. Perderlo o
restaurar una copia vieja rompe esa garantía.

## FileStorage

```php
use Afiphp\Storage\FileStorage;

$storage = new FileStorage('/privado/arca', lockTimeout: 120);
```

Usá un directorio privado, persistente y fuera del webroot. Adentro:

| Directorio | Contiene | Cuidado |
|---|---|---|
| `tickets/` | Sesiones de autenticación, cifradas. | No borrarlas para “forzar un login”. |
| `issuance/` | Pedidos, reservas, resultados e historial. | Registro durable; sin cifrar. |
| `diagnostics/` | SOAP redactado, si `saveSoapMessages` está activo. | Puede tener datos personales. |

- Crea directorios `0700` y archivos `0600`. Rechaza directorios con permisos
  de grupo u otros, sin corregirlos.
- Las escrituras son atómicas, con `fsync` y checksum. Si algo falla, la
  emisión se detiene en lugar de seguir.
- No limpia nada automáticamente.

### Dónde no usarlo

`FileStorage` sirve para **un solo host con disco local**, compartido por
todos sus workers. No usarlo con NFS, discos efímeros, un almacenamiento por
contenedor ni varios hosts emitiendo para la misma CUIT.

`lockTimeout` es cuánto espera para obtener el bloqueo de emisión (el de
autenticación está en `NetworkOptions`). No limita cuánto dura el bloqueo.

## Operación

- Respaldá el directorio y ensayá cómo recuperar una operación incierta.
- **No borres registros para desbloquear una serie.**
- No restaures un respaldo con emisores activos: detenelos y conciliá antes.
  Volver atrás el disco no vuelve atrás ARCA.
- Una política de retención de diagnósticos no habilita a borrar `issuance/`
  ni tickets vigentes.

## Otros backends

Para varios hosts, implementá `Afiphp\Storage\Storage` con coordinación
distribuida y pasalo como `storage:` en `Configuration`. También podés decorar
`FileStorage` para agregar métricas, sin cambiar claves ni debilitar bloqueos.

| Método | Debe |
|---|---|
| `readTicket(string $key): ?string` | Devolver el ticket guardado o `null` si no existe. |
| `writeTicket(string $key, string $ticket): void` | Guardarlo de forma atómica y privada. |
| `withTicketLock(string $key, int $timeout, callable $callback): mixed` | Excluir otros logins durante todo el callback. |
| `writeDiagnostic(string $key, string $xml): void` | Guardar el XML redactado, o no hacer nada. |
| `exclusively(string $scope, callable $callback): mixed` | Excluir a todos los emisores del scope (CUIT y ambiente) y pasar un `IssuanceJournal` al callback. |
| `IssuanceJournal::all(): array` | Devolver todos los registros del scope. |
| `IssuanceJournal::save(string $key, array $record): void` | Persistir el registro completo **antes de retornar**. |

Reglas:

1. El bloqueo cubre **todo el callback**, incluidas las llamadas a ARCA.
2. Cada `save()` queda confirmado aunque después falle el callback. Por eso
   **no sirve** una transacción que se revierta ante un error de red.
3. El bloqueo no puede vencer durante una emisión (no sirve un lock de Redis
   con TTL).
4. Guardá los registros tal cual, sin convertir decimales ni quitar campos.
   Claves y scopes son opacos.

Antes de usarlo, probá: dos procesos con la misma operación, operaciones
distintas en la misma serie, caída después de guardar el pedido, respuesta
perdida, falla al guardar el resultado. Ninguno debe producir otra emisión.
