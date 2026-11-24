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
    $result = $venta->numeroSinConfirmar === null
        ? $arca->issue($invoice)
        : $arca->retryIssue($invoice, $venta->numeroSinConfirmar);
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
| `UnconfirmedIssue` | La autorización sigue sin confirmarse. | Guardar `$e->number` y pasarlo a `retryIssue()`. |
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

Pasá el número guardado a `retryIssue($invoice, $numeroGuardado)`. Dentro del
bloqueo del punto de venta, lee una sola vez el último número autorizado y lo
compara con el número guardado. Por ejemplo, si guardaste el 50:

| Último autorizado | Qué hace `retryIssue()` |
|---|---|
| 50 o más | Consulta el 50. Si es la misma factura, devuelve su CAE y su fecha. Si es otro comprobante, emite con el siguiente número de la secuencia que leyó. |
| 49 | Envía con el 50. Ante un rechazo, consulta el 50. Si encuentra la misma factura, devuelve su CAE. Si está libre o contiene otro comprobante, propaga el rechazo. |
| 48 o menos | Lanza `InvalidArgumentException` sin enviar una solicitud de autorización. |

Si no se puede comprobar qué pasó, por ejemplo porque se pierde también la
respuesta del reintento, `retryIssue()` lanza `UnconfirmedIssue` con el número
que corresponde guardar.

Si el último autorizado es 50 o más pero ARCA no encuentra el 50, ARCA se
contradice y reintentar no lo resuelve: `retryIssue()` lanza
`RuntimeException` sin enviar nada. Conciliá ese número a mano con ARCA.

`retryIssue()` compara los datos fiscales sin la fecha del comprobante, porque
el reintento puede hacerse otro día. Conservá los datos originales, pero poné
una fecha que ARCA acepte, por ejemplo la de hoy: si reenvía con una fecha
vieja, ARCA la rechaza. Usá `retryIssue()` para una factura sin confirmar:
llamar a `issue()` podría emitirla otra vez con un número nuevo.

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
último número hasta que termina la operación, `issue()` y `retryIssue()`
retienen un bloqueo del [almacenamiento](almacenamiento.md). Otro worker que
emite en ese punto de venta espera; uno que emite en otro punto de venta, no.

- El bloqueo alcanza a los procesos que comparten el almacenamiento. Otro
  sistema que emite en el mismo punto de venta no lo ve: dale a la aplicación
  un punto de venta propio.
- Si no se obtiene en `NetworkOptions::$salesPointLockTimeout` segundos (120
  por defecto), la llamada lanza `LockTimeout` sin haber enviado nada:
  repetí la llamada.
