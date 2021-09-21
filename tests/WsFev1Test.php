<?php

use Afiphp\Entities\Customer;
use Afiphp\Entities\Invoice;
use Afiphp\Enums\IdentityDocumentType;
use Afiphp\Enums\InvoiceType;
use Afiphp\Exceptions\WsException;
use Afiphp\Webservices\WsFev1;

beforeEach(function () {
    $this->wsfe = new WsFev1('20920808582', __DIR__ . '/resources');
    $this->invoice = Invoice::createProductInvoice(
        InvoiceType::FACTURA_A,
        2,
        150,
        0,
        0,
        new Customer(IdentityDocumentType::CUIT, '20111111112')
    )
    ->addTribute(99, 'Ingresos Brutos', 150, 5.2, 7.8)
    ->addTax(5, 150, 31.5);
});

it('retrieves last authorized invoice number', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $lastNumber = $wsfe->getLastAuthorizedInvoiceNumber(InvoiceType::FACTURA_A, 2);

    expect($lastNumber)->toBeGreaterThan(0);
});

it('retrieves next invoice number', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $lastNumber = $wsfe->getLastAuthorizedInvoiceNumber(InvoiceType::FACTURA_A, 2);
    $nextNumber = $wsfe->getNextInvoiceNumber(InvoiceType::FACTURA_A, 2);

    expect($nextNumber)->toBe($lastNumber + 1);
});

it('retrieves invoices types', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $invoiceTypes = $wsfe->getInvoiceTypes();

    expect($invoiceTypes)->toBeArray();
});

it('retrieves concepts', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $concepts = $wsfe->getConcepts();

    expect($concepts)->toBeArray();
});

it('retrieves identity document types', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $identityDocumentTypes = $wsfe->getIdentityDocumentTypes();

    expect($identityDocumentTypes)->toBeArray();
});

it('retrieves taxes', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $taxes = $wsfe->getTaxes();

    expect($taxes)->toBeArray();
});

it('retrieves currencies', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $currencies = $wsfe->getCurrencies();

    expect($currencies)->toBeArray();
});

it('retrieves optionals', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $optionals = $wsfe->getOptionals();

    expect($optionals)->toBeArray();
});

it('retrieves tributes', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $tributes = $wsfe->getTributes();

    expect($tributes)->toBeArray();
});

it('retrieves currency', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $currency = $wsfe->getCurrency('DOL');

    expect($currency)->toHaveProperty('code', 'DOL');
    expect($currency->rate)->toBeGreaterThan(0);
});

it('retrieves sales points', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $salesPoints = $wsfe->getSalesPoints();

    expect($salesPoints)->toBeArray();
});

it('retrieves available sales points', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $salesPoints = $wsfe->getAvailableSalesPoints();

    expect($salesPoints)
        ->toBeArray()
        ->each(fn ($s) => $s->isLocked->toBeFalse());
});

it('creates approved CAE from invoice', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $nextNumber = $wsfe->getNextInvoiceNumber(InvoiceType::FACTURA_A, 2);
    $cae = $wsfe->createCaeFromInvoice($this->invoice);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->invoiceType)->toBe(InvoiceType::FACTURA_A);
    expect($cae->salesPoint)->toBe(2);
    expect($cae->firstInvoiceNumber)->toBe($nextNumber);
    expect($cae->lastInvoiceNumber)->toBe($nextNumber);
    expect($cae->number)->not()->toBeNull();
    expect($cae->dueDate)->not()->toBeNull();
});

it('creates approved CAE from credit note', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $nextCreditNoteNumber = $wsfe->getNextInvoiceNumber(InvoiceType::NOTA_CREDITO_A, 2);

    /** @var Invoice */
    $creditNote = (clone $this->invoice);
    $creditNote->type = InvoiceType::NOTA_CREDITO_A;
    $creditNote->addRelatedInvoice(InvoiceType::FACTURA_A, 2, 50);

    $cae = $wsfe->createCaeFromInvoice($creditNote);

    expect($cae->isApproved())->toBeTrue();
    expect($cae->invoiceType)->toBe(InvoiceType::NOTA_CREDITO_A);
    expect($cae->salesPoint)->toBe(2);
    expect($cae->firstInvoiceNumber)->toBe($nextCreditNoteNumber);
    expect($cae->lastInvoiceNumber)->toBe($nextCreditNoteNumber);
    expect($cae->number)->not()->toBeNull();
    expect($cae->dueDate)->not()->toBeNull();
});

it('creates approved CAE from batch', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $nextNumber = $wsfe->getNextInvoiceNumber(InvoiceType::FACTURA_A, 2);
    $caes = $wsfe->createCaeFromBatch([
        clone $this->invoice,
        clone $this->invoice,
        clone $this->invoice,
        clone $this->invoice,
        clone $this->invoice,
        clone $this->invoice,
    ]);

    expect($caes)->toBeArray();
    expect($caes)->toHaveCount(6);
    expect($caes)->each(function ($cae) use (&$nextNumber) {
        $cae->isApproved()->toBeTrue();
        $cae->invoiceType->toBe(InvoiceType::FACTURA_A);
        $cae->salesPoint->toBe(2);
        $cae->firstInvoiceNumber->toBe($nextNumber);
        $cae->lastInvoiceNumber->toBe($nextNumber);
        $cae->number->not()->toBeNull();
        $cae->dueDate->not()->toBeNull();

        $nextNumber++;
    });
});

it('retrieves CAE', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $cae = $wsfe->getCae(InvoiceType::FACTURA_A, 2, 50);

    expect($cae->invoiceType)->toBe(1);
    expect($cae->salesPoint)->toBe(2);
    expect($cae->firstInvoiceNumber)->toBe(50);
    expect($cae->lastInvoiceNumber)->toBe(50);
    expect($cae->number)->toBe('71372966559657');
    expect($cae->isApproved())->toBeTrue();
});

it('retrieves innvoice', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $invoice = $wsfe->getInvoice(InvoiceType::FACTURA_A, 2, 50);

    expect($invoice->number)->toBe(50);
    expect($invoice->salesPoint)->toBe(2);
    expect($invoice->cae->number)->toBe('71372966559657');
    expect($invoice->cae->isApproved())->toBeTrue();
});

it('throws exception', function () {
    /** @var WsFev1 */
    $wsfe = $this->wsfe;

    $wsfe->getCurrency('PES');
})->throws(WsException::class);
