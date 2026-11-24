# Pasar a producción

[Inicio](../README.md) · [Configuración](configuracion.md) · [Errores y reintentos](conciliacion.md)

En homologación nada tiene valor fiscal. En producción, cada comprobante
autorizado es un documento real y ARCA no lo anula: un error se corrige con una
nota de crédito, que también es real. Repasá esta lista antes de cambiar el
ambiente.

## 1. Credenciales de producción

Homologación y producción tienen certificados y puntos de venta propios. Nada
de lo que habilitaste en WSASS sirve en producción.

- Generá la clave y el CSR con
  `vendor/bin/afiphp init --cuit 20111111112 --env production` y seguí los
  pasos que imprime: el certificado sale de **Administración de Certificados
  Digitales** y se asocia a Facturación Electrónica en el **Administrador de
  Relaciones**.
- Creá un punto de venta para web services en **Administración de Puntos de
  Venta y Domicilios**: "RECE para aplicativo y Web Services" si sos
  responsable inscripto, "Factura Electrónica - Monotributo - Web Services" si
  sos monotributista. Uno de "Comprobantes en línea" no sirve.
- Dale a la aplicación **un punto de venta propio**. El
  [bloqueo de emisión](conciliacion.md#emisiones-simultáneas) no alcanza a otro
  sistema que use el mismo.
- Corré `vendor/bin/afiphp check --env production --cuit 20111111112 --sales-point N`
  con el almacenamiento de la aplicación (`--storage`). Tiene que terminar en
  "Listo".
- Guardá la clave en el gestor de secretos de tu plataforma o en un archivo
  privado fuera del webroot. Nunca en el repositorio.

## 2. Configuración

- `environment: Environment::PRODUCTION`, explícito. El valor por defecto es
  homologación, donde nada tiene valor fiscal y el certificado de producción
  no inicia sesión.
- `vatCondition` es la condición del emisor **hoy**. Si cambia (por ejemplo,
  de monotributo a responsable inscripto), actualizala: de ella sale la clase
  A, B o C.
- `saveSoapMessages` en `false`, salvo para diagnosticar algo puntual: los
  mensajes guardados tienen datos de clientes.
- Pasá un logger PSR-3 (ver [logs](configuracion.md#logs)).

## 3. Un almacenamiento compartido

- Todos los workers y procesos que emiten con la misma CUIT usan **el mismo**
  almacenamiento. Con dos, ARCA rechaza el login del segundo
  (`coe.alreadyAuthenticated`) y las emisiones de un mismo punto de venta ya no
  se coordinan.
- `FileStorage` sirve para un solo servidor. Con varios, implementá
  [otro backend](almacenamiento.md#otros-backends) con bloqueos reales.
- El directorio no puede ser temporal ni borrarse en cada deploy.

## 4. Números sin confirmar

- Ante `UnconfirmedIssue`, guardá `$e->number` con la venta, en la misma
  transacción en la que registrás el intento. Es lo único que evita duplicar la
  factura en el próximo intento (ver [errores y reintentos](conciliacion.md)).
- En reintentos automáticos y colas, llamá a `retryIssue()` cuando la venta
  tenga un número sin confirmar.
- No cambies los datos de una factura con un número sin confirmar, salvo la
  fecha.
- Cuando una factura se autoriza con un número, borralo de cualquier otra que
  lo tenga guardado como sin confirmar: ese intento no llegó, y `retryIssue()`
  podría tomar una factura idéntica como propia (ver
  [facturas idénticas](conciliacion.md#facturas-idénticas)).

## 5. Monitoreo

- Alertá con los registros `error` (emisión sin confirmar, número a conciliar
  a mano o configuración rechazada, que impide facturar hasta corregirla) y
  `warning` (rechazos, ARCA sin responder y certificado por vencer).
- Llamá a `check()` una vez por día, por ejemplo desde un cron: avisa 30 días
  antes de que venza el certificado, y falla si ARCA dejó de aceptarlo.
- Los certificados de ARCA vencen. Renovalo con un CSR nuevo antes de
  `$credentials->expiresAt` y cambiá las credenciales sin cambiar el
  almacenamiento.

## 6. El comprobante impreso

- ARCA no genera el PDF de lo que se emite por web service: lo arma tu sistema,
  con los datos que exige la norma. Ver [comprobante impreso](impresion.md).
- Guardá con cada venta lo necesario para reimprimir: el `Invoice` (o los datos
  para rearmarlo), las descripciones de los ítems, el número, el CAE y su
  vencimiento.

## 7. Revisión

- Las pruebas de la biblioteca no certifican cumplimiento fiscal. Que un
  contador revise la clase, las alícuotas, los tributos y el comprobante
  impreso de tus casos reales.
- Revisá los primeros comprobantes reales en **Mis Comprobantes** de ARCA.
