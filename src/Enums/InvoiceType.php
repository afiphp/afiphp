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
    public const RECIBOS_A = 4;
    public const NOTAS_VENTA_AL_CONTADO_A = 5;
    public const RECIBOS_B = 9;
    public const NOTAS_VENTA_AL_CONTADO_B = 10;
    public const LIQUIDACION_A = 63;
    public const LIQUIDACION_B = 64;
    public const COMPROBANTES_A_1415 = 34;
    public const COMPROBANTES_B_1415 = 35;
    public const OTROS_COMPROBANTES_A_1415 = 39;
    public const OTROS_COMPROBANTES_B_1415 = 40;
    public const CTA_VTA_Y_LIQUIDO_PROD_A = 60;
    public const CTA_VTA_Y_LIQUIDO_PROD_B = 61;
    public const FACTURA_C = 11;
    public const NOTA_DEBITO_C = 12;
    public const NOTA_CREDITO_C = 13;
    public const RECIBO_C = 15;
    public const COMPROBANTE_COMPRA_BIENES_USADOS_A_CONSUMIDOR_FINAL = 49;
    public const FACTURA_M = 51;
    public const NOTA_DEBITO_M = 52;
    public const NOTA_CREDITO_M = 53;
    public const RECIBO_M = 54;
    public const FACTURA_CREDITO_MIPYMES_A = 201;
    public const NOTA_DEBITO_MIPYMES_A = 202;
    public const NOTA_CREDITO_MIPYMES_A = 203;
    public const FACTURA_CREDITO_MIPYMES_B = 206;
    public const NOTA_DEBITO_MIPYMES_B = 207;
    public const NOTA_CREDITO_MIPYMES_B = 208;
    public const FACTURA_CREDITO_MIPYMES_C = 211;
    public const NOTA_DEBITO_MIPYMES_C = 212;
    public const NOTA_CREDITO_MIPYMES_C = 213;

    public static function all(): array
    {
        return [
            self::FACTURA_A => "Factura A",
            self::NOTA_DEBITO_A => "Nota de Débito A",
            self::NOTA_CREDITO_A => "Nota de Crédito A",
            self::FACTURA_B => "Factura B",
            self::NOTA_DEBITO_B => "Nota de Débito B",
            self::NOTA_CREDITO_B => "Nota de Crédito B",
            self::RECIBOS_A => "Recibos A",
            self::NOTAS_VENTA_AL_CONTADO_A => "Notas de Venta al contado A",
            self::RECIBOS_B => "Recibos B",
            self::NOTAS_VENTA_AL_CONTADO_B => "Notas de Venta al contado B",
            self::LIQUIDACION_A => "Liquidacion A",
            self::LIQUIDACION_B => "Liquidacion B",
            self::COMPROBANTES_A_1415 => "Cbtes. A del Anexo I, Apartado A,inc.f),R.G.Nro. 1415",
            self::COMPROBANTES_B_1415 => "Cbtes. B del Anexo I,Apartado A,inc. f),R.G. Nro. 1415",
            self::OTROS_COMPROBANTES_A_1415 => "Otros comprobantes A que cumplan con R.G.Nro. 1415",
            self::OTROS_COMPROBANTES_B_1415 => "Otros comprobantes B que cumplan con R.G.Nro. 1415",
            self::CTA_VTA_Y_LIQUIDO_PROD_A => "Cta de Vta y Liquido prod. A",
            self::CTA_VTA_Y_LIQUIDO_PROD_B => "Cta de Vta y Liquido prod. B",
            self::FACTURA_C => "Factura C",
            self::NOTA_DEBITO_C => "Nota de Débito C",
            self::NOTA_CREDITO_C => "Nota de Crédito C",
            self::RECIBO_C => "Recibo C",
            self::COMPROBANTE_COMPRA_BIENES_USADOS_A_CONSUMIDOR_FINAL => "Comprobante de Compra de Bienes Usados a Consumidor Final",
            self::FACTURA_M => "Factura M",
            self::NOTA_DEBITO_M => "Nota de Débito M",
            self::NOTA_CREDITO_M => "Nota de Crédito M",
            self::RECIBO_M => "Recibo M",
            self::FACTURA_CREDITO_MIPYMES_A => "Factura de Crédito electrónica MiPyMEs (FCE) A",
            self::NOTA_DEBITO_MIPYMES_A => "Nota de Débito electrónica MiPyMEs (FCE) A",
            self::NOTA_CREDITO_MIPYMES_A => "Nota de Crédito electrónica MiPyMEs (FCE) A",
            self::FACTURA_CREDITO_MIPYMES_B => "Factura de Crédito electrónica MiPyMEs (FCE) B",
            self::NOTA_DEBITO_MIPYMES_B => "Nota de Débito electrónica MiPyMEs (FCE) B",
            self::NOTA_CREDITO_MIPYMES_B => "Nota de Crédito electrónica MiPyMEs (FCE) B",
            self::FACTURA_CREDITO_MIPYMES_C => "Factura de Crédito electrónica MiPyMEs (FCE) C",
            self::NOTA_DEBITO_MIPYMES_C => "Nota de Débito electrónica MiPyMEs (FCE) C",
            self::NOTA_CREDITO_MIPYMES_C => "Nota de Crédito electrónica MiPyMEs (FCE) C",
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
