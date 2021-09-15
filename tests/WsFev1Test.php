<?php

use Afiphp\WebServices\WsFeV1;
use Afiphp\Entities\Invoice;
use Afiphp\Enums\InvoiceType;
use Afiphp\Entities\Customer;
use Afiphp\WebServices\Cae;

beforeEach(function () {
    $this->wsfe = new WsFeV1('20920808582', __DIR__ . '/resources');
    $this->invoice = Invoice::createProductInvoice(InvoiceType::FACTURA_A, 2, 150, 0, 0 , new Customer(80, '20111111112'))
        ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
        ->addTax(5, 150, 31.5);
});

it('creates approved CAE from invoice', function () {
    $nextNumber = $this->wsfe->getNextInvoiceNumber(InvoiceType::FACTURA_A, 2);

    /** @var Cae */
    $cae = $this->wsfe->createCaeForInvoice($this->invoice);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::FACTURA_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFirstInvoiceNumber())->toBe($nextNumber);
    expect($cae->getLastInvoiceNumber())->toBe($nextNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('creates approved CAE from credit note', function () {
    $nextCreditNoteNumber = $this->wsfe->getNextInvoiceNumber(InvoiceType::NOTA_CREDITO_A, 2);

    /** @var Invoice */
    $creditNote = (clone $this->invoice)
        ->setType(InvoiceType::NOTA_CREDITO_A)
        ->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50);

    /** @var Cae */
    $cae = $this->wsfe->createCaeForInvoice($creditNote);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->getInvoiceType())->toBe(InvoiceType::NOTA_CREDITO_A);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFirstInvoiceNumber())->toBe($nextCreditNoteNumber);
    expect($cae->getLastInvoiceNumber())->toBe($nextCreditNoteNumber);
    expect($cae->getNumber())->not()->toBeNull();
    expect($cae->getDueDate())->not()->toBeNull();
});

it('gets CAE', function () {
    /** @var Cae */
    $cae = $this->wsfe->getCae(InvoiceType::FACTURA_A, 2, 50);

    expect($cae->getInvoiceType())->toBe(1);
    expect($cae->getSalesPoint())->toBe(2);
    expect($cae->getFirstInvoiceNumber())->toBe(50);
    expect($cae->getLastInvoiceNumber())->toBe(50);
    expect($cae->getNumber())->toBe('71372966559657');
    expect($cae->isApproved())->toBeTrue();
});