<?php

use Afiphp\WebServices\WsFEV1;
use Afiphp\InvoiceType;
use Afiphp\Invoice;

beforeEach(function () {
    $this->wsfe = new WsFEV1('20920808582', __DIR__ . '/resources');
    $this->invoice = Invoice::createProductInvoice(
        InvoiceType::FACTURA_A,
        2,
        150,
        0,
        0,
        80,
        '20111111112'
    )
    ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
    ->addTax(5, 150, 31.5);
});

it('retrieves CAE', function () {
    $cae = $this->wsfe->getCAE(InvoiceType::FACTURA_A, 2, 50);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::FACTURA_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFromInvoiceNumber())->toBe(50);
    expect($cae->getToInvoiceNumber())->toBe(50);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('creates approved CAE from invoice', function () {
    $nextNumber = $this->wsfe->getLastNumber(InvoiceType::FACTURA_A, 2) + 1;
    $cae = $this->wsfe->createCAEFromInvoice($this->invoice);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::FACTURA_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFromInvoiceNumber())->toBe($nextNumber);
    expect($cae->getToInvoiceNumber())->toBe($nextNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('creates approved CAE from credit note', function () {
    $nextNumber = $this->wsfe->getLastNumber(InvoiceType::NOTA_CREDITO_A, 2) + 1;

    $creditNote = $this->invoice
        ->withType(InvoiceType::NOTA_CREDITO_A)
        ->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50);

    $cae = $this->wsfe->createCAEFromInvoice($creditNote);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::NOTA_CREDITO_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFromInvoiceNumber())->toBe($nextNumber);
    expect($cae->getToInvoiceNumber())->toBe($nextNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});
