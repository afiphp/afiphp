<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use Afiphp\Internal\Billing\Amounts;
use Afiphp\Internal\Billing\InvoiceMapper;
use InvalidArgumentException;

/**
 * RG 4892 QR URL of an authorized voucher. An issued result already carries it in $result->qr;
 * this builds it again from the data the application kept. Numbers are written from exact
 * decimal strings, never through floats.
 */
final class QrCode
{
    public const URL = 'https://www.arca.gob.ar/fe/qr/';

    /**
     * @param int $total Integer cents.
     * @param Receiver|null $receiver Optional in RG 4892: omit it when the receiver data may have changed.
     */
    public static function url(
        string $issuerCuit,
        InvoiceReference $voucher,
        int $total,
        string $cae,
        ?Receiver $receiver = null,
        ?Currency $currency = null,
    ): string {
        Receiver::validateCuit($issuerCuit);
        if (!preg_match('/^[0-9]{14}$/D', $cae) || $cae === '00000000000000') {
            throw new InvalidArgumentException('Invalid authorization code.');
        }
        $currency ??= Currency::pesos();
        $fields = [
            'ver' => '1',
            'fecha' => json_encode($voucher->date->format('Y-m-d'), JSON_THROW_ON_ERROR),
            'cuit' => self::integer($issuerCuit),
            'ptoVta' => self::integer($voucher->salesPoint),
            'tipoCmp' => self::integer(InvoiceMapper::type($voucher->class, $voucher->kind)),
            'nroCmp' => self::integer($voucher->number),
            'importe' => Amounts::decimal($total),
            'moneda' => json_encode(InvoiceMapper::currency($currency), JSON_THROW_ON_ERROR),
            'ctz' => $currency->exchangeRate,
        ];
        if ($receiver !== null) {
            $fields['tipoDocRec'] = self::integer(InvoiceMapper::documentType($receiver->documentType));
            $fields['nroDocRec'] = self::integer($receiver->document);
        }
        $fields['tipoCodAut'] = '"E"';
        $fields['codAut'] = self::integer($cae);
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
