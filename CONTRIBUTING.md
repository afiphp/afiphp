# Contribuir

[Inicio](README.md) · [Referencia](docs/referencia.md)

## Entorno

PHP 8.3+ de 64 bits, Composer, las extensiones del paquete y `pcntl` (solo para
las pruebas). CI prueba PHP 8.3, 8.4 y 8.5.

```bash
composer install
composer format:check   # composer format para aplicarlo (Mago, mago.toml)
composer test           # No contacta ARCA.
composer analyse
composer validate --strict
```

## Pruebas contra homologación

`composer test:arca` **sí contacta ARCA** y emite comprobantes de prueba. Los
endpoints de homologación están fijados en el test: no puede apuntar a
producción.

Necesitás un certificado de homologación con `wsfe` delegado a la CUIT:

```bash
ARCA_CUIT=20111111112 \
ARCA_CERT=/ruta/homo.crt \
ARCA_KEY=/ruta/homo.key \
ARCA_SALES_POINT=1 \
composer test:arca
```

Variables opcionales:

| Variable | Predeterminado |
|---|---|
| `ARCA_KEY_PASSPHRASE` | Vacía. |
| `ARCA_VAT_CONDITION` | `RESPONSABLE_INSCRIPTO` (nombre del caso de `VatCondition`). |
| `ARCA_STORAGE` | Directorio temporal + `/afiphp-homologation-` + CUIT. |

La suite cubre facturas a consumidor final, a responsable inscripto y en
dólares; notas totales (con `fullCreditNote()`), parciales, de débito y por
período; consultas con `voucher()` y `lastNumber()`; `check()` con el punto de
venta; conciliación; un rechazo; los catálogos de ARCA y el Padrón.
Las pruebas del Padrón se saltean si el certificado no tiene
`ws_sr_constancia_inscripcion` o `ws_sr_padron_a13`. Usan la CUIT `30500010912`
y el DNI `20173159`, que existen en el Padrón de prueba; si ARCA los quita,
actualizá `REGISTRY_CUIT` y `REGISTRY_DNI`.

La suite guarda SOAP de diagnóstico: protegé ese directorio.

## Documentación

- El README tiene instalación y un ejemplo; `docs/` explica casos de uso y
  `referencia.md` lista la API.
- Si cambiás una firma pública, actualizá la referencia y los ejemplos.
- Cada regla se explica en un solo lugar; las demás guías la enlazan.
