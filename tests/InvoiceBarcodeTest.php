<?php

use Afiphp\Utils\InvoiceBarcodeGenerator;
use Afiphp\Enums\InvoiceType;
use Afiphp\Entities\Cae;

it('can generate barcode', function () {
    expect(
        InvoiceBarcodeGenerator::make('20111111112', InvoiceType::FACTURA_A, 2, '71372966559657', new DateTime('2021-09-23'))
    )->toBe('203296972240110000168104053456033201803168');
});

it('can generate barcode from CAE', function () {
    $cae = new Cae('A', InvoiceType::FACTURA_A, 2, 1, 1, '71372966559657', new DateTime('2021-09-23'));

    expect(
        InvoiceBarcodeGenerator::makeFromCae('20111111112', $cae)
    )->toBe('201111111120010000271372966559657202109230');
});
