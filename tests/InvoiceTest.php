<?php

use Afiphp\Entities\Customer;
use Afiphp\Entities\Invoice;
use Afiphp\Enums\IdentityDocumentType;
use Afiphp\Enums\InvoiceType;

it('can be converted to WsFev1', function () {
    $data = Invoice::createProductInvoice(
        InvoiceType::FACTURA_A,
        2,
        150,
        0,
        0,
        new Customer(IdentityDocumentType::CUIT, '20111111112'),
        new DateTime('2021-09-17')
    )
    ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
    ->addTax(5, 150, 31.5)
    ->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50)
    ->addOptional('A1', 'B2')
    ->toWsFev1InvoiceRequest();

    expect($data)->toMatchArray([
        'PtoVta' => 2,
        'CbteTipo' => 1,
        'CbteDesde' => null,
        'CbteHasta' => null,
        'CbteFch' => '20210917',
        'Concepto' => 1,
        'DocTipo' => 80,
        'DocNro' => '20111111112',
        'ImpTotal' => 189.3,
        'ImpTotConc' => 0.0,
        'ImpNeto' => 150.0,
        'ImpOpEx' => 0.0,
        'ImpIVA' => 31.5,
        'ImpTrib' => 7.8,
        'MonId' => 'PES',
        'MonCotiz' => 1.0,
        'FchServDesde' => '',
        'FchServHasta' => '',
        'FchVtoPago' => '',
        'Tributos' => [
            [
                'Id' => 99,
                'Desc' => 'Ingresos Brutos',
                'BaseImp' => 150.0,
                'Alic' => 5.2,
                'Importe' => 7.8,
            ],
        ],
        'Iva' => [
            [
                'Id' => 5,
                'BaseImp' => 150.0,
                'Importe' => 31.5,
            ],
        ],
        'Opcionales' => [
            [
                'Id' => 'A1',
                'Valor' => 'B2',
            ],
        ],
        'CbtesAsoc' => [
            [
                'Tipo' => 1,
                'PtoVta' => 2,
                'Nro' => 50,
            ],
        ],
    ]);
});
