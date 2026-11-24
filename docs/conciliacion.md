# Errores y reintentos

[Inicio](../README.md) · [Referencia](referencia.md) · [Almacenamiento](almacenamiento.md)

ARCA no tiene clave de idempotencia. Si se pierde la respuesta de una emisión,
no se sabe si la factura existe, y emitir de nuevo con otro número podría
duplicarla. afiphp no guarda nada para resolverlo: te devuelve el número del
intento y vos lo guardás con la factura.

## Qué puede pasar al emitir

```php
use Afiphp\Exceptions\{ArcaUnavailable, ConfigurationError, InvoiceRejected, LockTimeout, UnconfirmedIssue};

try {
    $result = $arca->issue($invoice, previousNumber: $venta->numeroSinConfirmar);
    // Autorizada: guardá número, CAE y fecha, y borrá el número sin confirmar.
} catch (InvoiceRejected $e) {
    // ARCA la rechazó: $e->observations dice por qué. Corregí y volvé a emitir.
} catch (UnconfirmedIssue $e) {
    // No se sabe si se autorizó. Guardá $e->number como número sin confirmar.
} catch (ArcaUnavailable $e) {
    // ARCA no responde. Esperá unos minutos y repetí la misma llamada.
} catch (ConfigurationError $e) {
    // Certificado, habilitación, reloj o TLS. Esperar no lo resuelve.
} catch (LockTimeout $e) {
    // Otra emisión en el mismo punto de venta tardó demasiado. No se envió nada: repetí.
}
```

| Resultado | Significa | Qué hacer |
|---|---|---|
| `IssueResult` | Autorizada. | Guardarla. Revisar `observations` igual. |
| `InvoiceRejected` | ARCA la rechazó; no existe. | Corregir según `observations` y emitir de nuevo. |
| `UnconfirmedIssue` | Se perdió la respuesta y la consulta no la confirmó. | Guardar `$e->number` y pasarlo como `previousNumber` en el próximo intento. |
| `ArcaUnavailable` | ARCA no respondió y el pedido no salió, o no pudo procesarlo. | Repetir más tarde. |
| `ConfigurationError` | ARCA no acepta el pedido por la configuración del emisor. | Avisar a un administrador. |
| `LockTimeout` | Otro worker retuvo el bloqueo del punto de venta, o el del login, más de lo permitido. No se envió nada. | Repetir. |

`ArcaUnavailable`, `ConfigurationError` y `LockTimeout` no cambian nada: si había un número sin
confirmar, sigue igual.

## Respuesta perdida

Cuando la respuesta de ARCA se pierde (un timeout, un error del servidor),
`issue()` consulta ese número una vez:

- si la factura está y coincide con la que se envió, la devuelve autorizada;
- si no, lanza `UnconfirmedIssue` con el número.

Si el pedido no llegó a salir (conexión rechazada, DNS, TLS o login fallido),
no hay nada que consultar: lanza `ArcaUnavailable` o `ConfigurationError`.

## Próximo intento

Pasá el número guardado como `previousNumber`. Antes de enviar, `issue()` lo
busca en ARCA:

| ARCA tiene en ese número | `issue()` |
|---|---|
| Esta factura | La devuelve autorizada, con la fecha con que se autorizó. No envía nada. |
| Nada | La envía con ese mismo número y la fecha de la factura. |
| Otro comprobante | El intento anterior no llegó: la emite con el próximo número. |
| No se puede consultar | Lanza la falla y no envía nada. |

Reenviar el mismo número nunca duplica: ARCA solo acepta el número siguiente al
último autorizado. Si rechaza el reenvío, `issue()` vuelve a consultar, por si
el intento anterior llegó tarde.

La factura se compara por tipo, punto de venta, receptor, importes y alícuotas,
**sin la fecha**: el próximo intento puede ser otro día. Por eso:

- **No cambies la factura** mientras tenga un número sin confirmar, salvo la
  fecha.
- **No emitas sin `previousNumber`** una factura que tiene un número sin
  confirmar: podrías duplicarla.

## Antes de emitir

| Método | Responde |
|---|---|
| `$arca->status()` | ¿ARCA funciona? No usa el certificado. |
| `$arca->check($salesPoint)` | ¿Puedo facturar ahora? Revisa la vigencia del certificado, inicia sesión, prueba la habilitación de la CUIT y, con un punto de venta, que esté activo. Lanza `ArcaUnavailable` o `ConfigurationError`. |

`check()` hace dos llamadas, tres si tiene que iniciar sesión: guardá su
respuesta un minuto en lugar de llamarlo en cada página.

Desde el cliente no se distingue "ARCA caído" de "este servidor sin conexión":
los dos son `ArcaUnavailable`. Conviene decir "no se pudo contactar a ARCA".

No envuelvas `issue()` en reintentos automáticos: cada intento debería pasar el
número sin confirmar del anterior.

## Emisiones simultáneas

Las emisiones en un mismo punto de venta se hacen de a una: desde que se lee el
último número hasta que ARCA responde, `issue()` retiene un bloqueo del
[almacenamiento](almacenamiento.md). Otro worker que emite en ese punto de
venta espera; uno que emite en otro punto de venta, no.

- El bloqueo alcanza a los procesos que comparten el almacenamiento. Otro
  sistema que emite en el mismo punto de venta no lo ve: dale a la aplicación
  un punto de venta propio.
- Si no se obtiene en `NetworkOptions::$salesPointLockTimeout` segundos (120
  por defecto), `issue()` lanza `LockTimeout` sin haber enviado nada:
  repetí la llamada.
