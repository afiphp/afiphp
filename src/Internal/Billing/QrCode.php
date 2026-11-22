<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

/**
 * @internal RG 4892 QR for a printed voucher, built from an authorized journal record.
 * Numbers are written from their exact decimal strings, never through floats.
 */
final class QrCode
{
    public const URL = 'https://www.arca.gob.ar/fe/qr/';

    public static function url(array $record, string $cae): string
    {
        $header = $record['request']['FeCAEReq']['FeCabReq'];
        $detail = $record['request']['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        $fields = [
            'ver' => '1',
            'fecha' => json_encode($record['date'], JSON_THROW_ON_ERROR),
            'cuit' => self::integer($record['issuerCuit']),
            'ptoVta' => self::integer($header['PtoVta']),
            'tipoCmp' => self::integer($header['CbteTipo']),
            'nroCmp' => self::integer($record['number']),
            'importe' => $detail['ImpTotal'],
            'moneda' => json_encode($detail['MonId'], JSON_THROW_ON_ERROR),
            'ctz' => $detail['MonCotiz'],
            'tipoDocRec' => self::integer($detail['DocTipo']),
            'nroDocRec' => self::integer($detail['DocNro']),
            'tipoCodAut' => '"E"',
            'codAut' => self::integer($cae),
        ];
        $json =
            '{'
            . implode(',', array_map(
                static fn (string $name, string $value): string => "\"{$name}\":{$value}",
                array_keys($fields),
                $fields,
            ))
            . '}';

        return self::URL . '?p=' . base64_encode($json);
    }

    /** A JSON integer token without leading zeros. */
    private static function integer(int|string $value): string
    {
        return ltrim((string) $value, '0') ?: '0';
    }
}
