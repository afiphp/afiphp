<?php

use Afiphp\WebServices\WsFev1;
use Afiphp\WebServices\WsAfip;
use Afiphp\Invoice;
use Afiphp\Enums\InvoiceType;
use Afiphp\Customer;
use Afiphp\Cae;

beforeEach(function () {
    $this->wsfe = new WsFev1('20920808582', __DIR__ . '/resources');

    $this->invoice = Invoice::createProductInvoice(InvoiceType::FACTURA_A, 2, 150, 0, 0 , new Customer(80, '20111111112'))
        ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
        ->addTax(5, 150, 31.5);
});

it('can be converted to AFIP service data', function () {
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
                    'CbteFch' => '20210914',
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

it('creates approved CAE from invoice', function () {
    $nextNumber = $this->wsfe->getLastNumber(InvoiceType::FACTURA_A, 2) + 1;
    
    /** @var Cae */
    $cae = $this->wsfe->createCAEFromInvoice($this->invoice);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::FACTURA_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFirstInvoiceNumber())->toBe($nextNumber);
    expect($cae->getLastInvoiceNumber())->toBe($nextNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('creates approved CAE from credit note', function () {
    $nextCreditNoteNumber = $this->wsfe->getLastNumber(InvoiceType::NOTA_CREDITO_A, 2) + 1;

    /** @var Invoice */
    $creditNote = (clone $this->invoice)
        ->setType(InvoiceType::NOTA_CREDITO_A)
        ->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50);

    /** @var Cae */
    $cae = $this->wsfe->createCAEFromInvoice($creditNote);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::NOTA_CREDITO_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFirstInvoiceNumber())->toBe($nextCreditNoteNumber);
    expect($cae->getLastInvoiceNumber())->toBe($nextCreditNoteNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('retrieves invoice', function () {
    /** @var Invoice */
    $invoice = $this->wsfe->getInvoice(1, 2, 50);

    expect($invoice->getType())->toBe(1);
    expect($invoice->getSalesPoint())->toBe(2);
    expect($invoice->getNumber())->toBe(50);
    expect($invoice->getCae()->getNumber())->toBe('71372966559657');
    expect($invoice->getCae()->isApproved())->toBeTrue();
});

it('retrieves CAE', function () {
    /** @var Cae */
    $cae = $this->wsfe->getCAE(InvoiceType::FACTURA_A, 2, 50);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::FACTURA_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFirstInvoiceNumber())->toBe(50);
    expect($cae->getLastInvoiceNumber())->toBe(50);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});