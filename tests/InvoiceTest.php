<?php

use Afiphp\WebServices\WsFeV1;
use Afiphp\WebServices\WsAfip;
use Afiphp\Invoice;
use Afiphp\Enums\InvoiceType;
use Afiphp\Customer;

beforeEach(function () {
    $this->invoice = Invoice::createProductInvoice(InvoiceType::FACTURA_A, 2, 150, 0, 0 , new Customer(80, '20111111112'))
        ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
        ->addTax(5, 150, 31.5);
});

it('can be converted to WsFeV1', function () {
    $data = (clone $this->invoice)
        ->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50)
        ->addOptional('A1', 'B2')
        ->toAfipService(WsAfip::WSFEV1);

    expect($data)->toMatchArray([
        'FeCAEReq' => [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => 2,
                'CbteTipo' => 1,
            ],
            'FeDetReq' => [
                'FECAEDetRequest' =>  [
                    'CbteDesde' => null,
                    'CbteHasta' => null,
                    'CbteFch' => '20210915',
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
                        ]
                    ],
                    'Iva' => [
                        [
                            'Id' => 5, 
                            'BaseImp' => 150.0, 
                            'Importe' => 31.5, 
                        ]
                    ],
                    'Opcionales' => [
                        [
                            'Id' => 'A1',
                            'Valor' => 'B2',
                        ]
                    ],
                    'CbtesAsoc' => [
                        [
                            'Tipo' => 1,
                            'PtoVta' => 2,
                            'Nro' => 50,
                        ]
                    ]
                ],
            ],
        ],
    ]);
});

it('can be created from WsFeV1', function () {
    $wsfe = new WsFeV1('20920808582', __DIR__ . '/resources');
    $data = $wsfe->getInvoice(InvoiceType::FACTURA_A, 2, 50);

    $invoice = Invoice::createFromWsFeV1($data);

    expect($invoice->getType())->toBe(1);
    expect($invoice->getSalesPoint())->toBe(2);
    expect($invoice->getNumber())->toBe(50);
    expect($invoice->getCae()->getNumber())->toBe('71372966559657');
    expect($invoice->getCae()->isApproved())->toBeTrue();
});
