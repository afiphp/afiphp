<?php

use Afiphp\WebServices\WsFEV1;
use Afiphp\InvoiceType;
use Afiphp\Invoice;

beforeEach(function () {
    $this->fe = new WsFEV1('20920808582', __DIR__ . '/resources');

    $salesPoints = $this->fe->getSalesPoints();
    $this->salesPoint = $salesPoints ? $salesPoints[0]->Nro : 0;
});

it('creates approved CAE from invoice', function () {
    $nextInvoiceNumber = $this->fe->getLastNumber(InvoiceType::FACTURA_A, $this->salesPoint) + 1;

    $invoice = Invoice::createProductInvoice(
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

    $cae = $this->fe->createCAEFromInvoice($invoice);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getFromInvoiceNumber())->toBe($nextInvoiceNumber);
    expect($cae->getToInvoiceNumber())->toBe($nextInvoiceNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});
