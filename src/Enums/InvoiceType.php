<?php

namespace Afiphp\Enums;

abstract class InvoiceType
{
    public const FACTURA_A = 1;
    public const NOTA_DEBITO_A = 2;
    public const NOTA_CREDITO_A = 3;
    public const FACTURA_B = 6;
    public const NOTA_DEBITO_B = 7;
    public const NOTA_CREDITO_B = 8;
    public const FACTURA_C = 11;
    public const NOTA_CREDITO_C = 13;
    public const CIERRE_Z = 58;
    public const REMITO = 69;
    public const PRESUPUESTO = 81;
    public const REMITO_STOCK = 82;
    public const DOCUMENTO_NO_FISCAL = 83;
    public const FACTURA_DE_EXPORTACION = 17;

    public static function all(): array
    {
        return [
            self::FACTURA_A => 'Factura A',
            self::NOTA_DEBITO_A => 'Nota de débito A',
            self::NOTA_CREDITO_A => 'Nota de crédito A',
            self::FACTURA_B => 'Factura B',
            self::NOTA_DEBITO_B => 'Nota de débito B',
            self::NOTA_CREDITO_B => 'Nota de crédito B',
            self::FACTURA_C => 'Factura C',
            self::NOTA_CREDITO_C => 'Nota de crédito C',
            self::CIERRE_Z => 'Cierre z',
            self::REMITO => 'Remito',
            self::PRESUPUESTO => 'Presupuesto',
            self::REMITO_STOCK => 'Remito de stock',
            self::DOCUMENTO_NO_FISCAL => 'Documento no fiscal',
            self::FACTURA_DE_EXPORTACION => 'Factura de exportacion',
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}