# Conciliación y reintentos

[Inicio](../README.md) · [Referencia](referencia.md) · [Almacenamiento](almacenamiento.md)

**Un timeout no demuestra que la factura no exista.** ARCA pudo haberla
autorizado y la respuesta perderse en el camino. Por eso cada emisión tiene un
ID y un registro local, y nunca se emite dos veces la misma venta.

## Qué hacer con cada estado

Una **serie** es un punto de venta y un tipo de comprobante, dentro de una CUIT
y un ambiente.

| `Outcome` | Significa | Qué hacer |
|---|---|---|
| `AUTHORIZED` | Autorizada con CAE válido. | Guardar el resultado. No volver a emitir. |
| `REJECTED` | ARCA la rechazó. | Revisar `observations`. Si corregís, usá un ID nuevo. |
| `INDETERMINATE` | No se sabe si se autorizó. **Bloquea la serie.** | Llamar `recover()`; si sigue igual, evaluar `retry()`. |
| `CONFLICT` | El número reservado tiene otro comprobante en ARCA. No bloquea. | Revisar ese comprobante a mano antes de facturar la venta con un ID nuevo. |
| `BLOCKED` | Otra operación pendiente (`blockedBy`) bloquea la serie. No se envió nada. | Resolver esa operación y volver a intentar. |

Ante `INDETERMINATE` o `BLOCKED`, **nunca** cambies el ID, la fecha ni borres el
registro para destrabar la serie: podrías duplicar una factura.

## ID de operación

Usá un ID de tu negocio, como `venta:123` o `venta:123:nc:1`, y guardalo con
los datos de la factura **antes de emitir**. Es único por CUIT y ambiente, de
hasta 200 bytes, sin espacios en los extremos ni secretos.

Si repetís un ID:

- **con los mismos datos**, devuelve el resultado guardado sin contactar ARCA;
- **con otros datos**, lanza `IdempotencyConflict` sin enviar nada.

La fecha y la cotización son parte de los datos. Para reintentar, reusá la
factura guardada; no la reconstruyas con la fecha o cotización de hoy.

Sin ID, `issue($invoice)` genera uno al azar en `$result->operation`. Sigue
siendo seguro ante incertidumbre, pero si tu aplicación vuelve a llamar
`issue()` sin ID, **puede emitir otra factura**. Usalo solo en scripts manuales.

## Recuperar

```php
$result = $arca->recover('venta:123');
```

Consulta en ARCA el número reservado y compara todos los datos fiscales. No
emite.

- Coinciden y hay CAE válido: `AUTHORIZED`.
- Hay otro comprobante: `CONFLICT`.
- No se encuentra o faltan datos: sigue `INDETERMINATE`. Un “no encontrado”
  aislado no libera la reserva.

Si la operación ya es definitiva, devuelve lo guardado.

## Reintentar

```php
$result = $arca->retry('venta:123');
```

Solo reenvía si ARCA confirma que el número no existe, la fecha sigue en
ventana y el último autorizado es el anterior al reservado. Envía **el mismo
pedido con el mismo número**; nunca cambia fechas ni importes. No reintenta
rechazos ni conflictos.

Si la fecha ya quedó fuera de ventana, lanza una excepción y la reserva sigue.
No hay forma automática de descartar una incertidumbre: investigala sin crear
otro ID para la misma venta.

## Excepciones

Los fallos de red o autenticación durante la autorización se devuelven como
`INDETERMINATE`. Otros fallos (por ejemplo, de almacenamiento) lanzan
excepciones. **Una excepción no es un rechazo.**

Si ARCA no responde antes de reservar el número (al iniciar sesión o al leer el
último número), `issue()` lanza `ServiceUnavailable` y no guarda nada: repetir
la misma llamada más tarde es seguro. Si ARCA no responde a la consulta de
`recover()` o `retry()`, la operación sigue `INDETERMINATE` con `reason`
`lookup_failed`.

```php
use Afiphp\Exceptions\{IdempotencyConflict, IssuancePersistenceException, ServiceUnavailable};

try {
    $result = $arca->issue($invoice, $operation);
} catch (ServiceUnavailable $e) {
    // ARCA no responde o tiene un error interno. Avisá y repetí la misma llamada más tarde.
} catch (IdempotencyConflict $e) {
    // El ID ya existe con otros datos. Recuperá los originales.
} catch (IssuancePersistenceException $e) {
    // Hubo resultado ($e->result) pero no se pudo guardar.
    // Arreglá el almacenamiento y llamá recover($operation).
} catch (\Throwable $e) {
    // Conservá factura e ID. No emitas con otro ID.
}
```

Para mostrar el estado del servicio antes de emitir, `$arca->status()` consulta
`FEDummy` sin iniciar sesión.

No envuelvas `issue()` en un mecanismo de reintentos automáticos. Si
`recover()` no conoce la operación, revisá CUIT, ambiente y almacenamiento.
