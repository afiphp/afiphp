<?php

namespace Afiphp\Enums;

abstract class IdentityDocumentType
{
    public const CUIT = 80;
    public const CUIL = 86;
    public const CDI = 87;
    public const LE = 89;
    public const LC = 90;
    public const CI_EXTRANJERA = 91;
    public const EN_TRAMITE = 92;
    public const ACTA_NACIMIENTO = 93;
    public const CI_BS_AS_RNP = 95;
    public const DNI = 96;
    public const PASAPORTE = 94;
    public const CI_POLICIA_FEDERAL = 0;
    public const CI_BUENOS_AIRES = 1;
    public const CI_CATAMARCA = 2;
    public const CI_CORDOBA = 3;
    public const CI_CORRIENTES = 4;
    public const CI_ENTRE_RIOS = 5;
    public const CI_JUJUY = 6;
    public const CI_MENDOZA = 7;
    public const CI_LA_RIOJA = 8;
    public const CI_SALTA = 9;
    public const CI_SAN_JUAN = 10;
    public const CI_SAN_LUIS = 11;
    public const CI_SANTA_FE = 12;
    public const CI_SANTIAGO_DEL_ESTERO = 13;
    public const CI_TUCUMAN = 14;
    public const CI_CHACO = 16;
    public const CI_CHUBUT = 17;
    public const CI_FORMOSA = 18;
    public const CI_MISIONES = 19;
    public const CI_NEUQUEN = 20;
    public const CI_LA_PAMPA = 21;
    public const CI_RIO_NEGRO = 22;
    public const CI_SANTA_CRUZ = 23;
    public const CI_TIERRA_DEL_FUEGO = 24;
    public const OTRO = 99;

    public static function all(): array
    {
        return [
            self::CUIT => "CUIT",
            self::CUIL => "CUIL",
            self::CDI => "CDI",
            self::LE => "LE",
            self::LC => "LC",
            self::CI_EXTRANJERA => "CI Extranjera",
            self::EN_TRAMITE => "en trámite",
            self::ACTA_NACIMIENTO => "Acta Nacimiento",
            self::CI_BS_AS_RNP => "CI Bs. As. RNP",
            self::DNI => "DNI",
            self::PASAPORTE => "Pasaporte",
            self::CI_POLICIA_FEDERAL => "CI Policía Federal",
            self::CI_BUENOS_AIRES => "CI Buenos Aires",
            self::CI_CATAMARCA => "CI Catamarca",
            self::CI_CORDOBA => "CI Córdoba",
            self::CI_CORRIENTES => "CI Corrientes",
            self::CI_ENTRE_RIOS => "CI Entre Ríos",
            self::CI_JUJUY => "CI Jujuy",
            self::CI_MENDOZA => "CI Mendoza",
            self::CI_LA_RIOJA => "CI La Rioja",
            self::CI_SALTA => "CI Salta",
            self::CI_SAN_JUAN => "CI San Juan",
            self::CI_SAN_LUIS => "CI San Luis",
            self::CI_SANTA_FE => "CI Santa Fe",
            self::CI_SANTIAGO_DEL_ESTERO => "CI Santiago del Estero",
            self::CI_TUCUMAN => "CI Tucumán",
            self::CI_CHACO => "CI Chaco",
            self::CI_CHUBUT => "CI Chubut",
            self::CI_FORMOSA => "CI Formosa",
            self::CI_MISIONES => "CI Misiones",
            self::CI_NEUQUEN => "CI Neuquén",
            self::CI_LA_PAMPA => "CI La Pampa",
            self::CI_RIO_NEGRO => "CI Río Negro",
            self::CI_SANTA_CRUZ => "CI Santa Cruz",
            self::CI_TIERRA_DEL_FUEGO => "CI Tierra del Fuego",
            self::OTRO => "Doc. (Otro)",
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
