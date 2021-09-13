<?php

use Afiphp\WebServices\WsFEV1;
use Afiphp\InvoiceType;
use Afiphp\Invoice;

beforeEach(function () {
    $this->fe = new WsFEV1('20920808582', __DIR__ . '/resources');

    $salesPoints = $this->fe->getSalesPoints();
    $this->salesPoint = $salesPoints ? $salesPoints[0]->Nro : 0;

    $this->invoice = Invoice::createProductInvoice(
        InvoiceType::FACTURA_A,
        $this->salesPoint,
        150,
        0,
        0,
        80,
        '20111111112'
    )
    ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
    ->addTax(5, 150, 31.5);
});

it('creates approved CAE from invoice', function () {
    $nextNumber = $this->fe->getLastNumber(InvoiceType::FACTURA_A, $this->salesPoint) + 1;

    $cae = $this->fe->createCAEFromInvoice($this->invoice);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::FACTURA_A);
    expect($cae->getSalesPoint())->toBe($this->salesPoint);
    expect($cae->getFromInvoiceNumber())->toBe($nextNumber);
    expect($cae->getToInvoiceNumber())->toBe($nextNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('creates approved CAE from credit note', function () {
    $nextNumber = $this->fe->getLastNumber(InvoiceType::NOTA_CREDITO_A, $this->salesPoint) + 1;

    $creditNote = $this->invoice
        ->withType(InvoiceType::NOTA_CREDITO_A)
        ->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50);

    $cae = $this->fe->createCAEFromInvoice($creditNote);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::NOTA_CREDITO_A);
    expect($cae->getSalesPoint())->toBe($this->salesPoint);
    expect($cae->getFromInvoiceNumber())->toBe($nextNumber);
    expect($cae->getToInvoiceNumber())->toBe($nextNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});
