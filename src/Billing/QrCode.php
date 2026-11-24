<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use Afiphp\Internal\Billing\Amounts;
use Afiphp\Internal\Billing\InvoiceMapper;
use Afiphp\Internal\Billing\QrCode as Payload;
use InvalidArgumentException;

/**
 * RG 4892 QR URL for printing a voucher that was authorized earlier, from the data the application kept.
 * An issued result already carries it in $result->qr.
 */
final class QrCode
{
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
            'cuit' => Payload::integer($issuerCuit),
            'ptoVta' => Payload::integer($voucher->salesPoint),
            'tipoCmp' => Payload::integer(InvoiceMapper::type($voucher->class, $voucher->kind)),
            'nroCmp' => Payload::integer($voucher->number),
            'importe' => Amounts::decimal($total),
            'moneda' => json_encode(InvoiceMapper::currency($currency), JSON_THROW_ON_ERROR),
            'ctz' => $currency->exchangeRate,
        ];
        if ($receiver !== null) {
            $fields['tipoDocRec'] = Payload::integer(InvoiceMapper::documentType($receiver->documentType));
            $fields['nroDocRec'] = Payload::integer($receiver->document);
        }

        return Payload::encode([...$fields, 'tipoCodAut' => '"E"', 'codAut' => Payload::integer($cae)]);
    }
}
