<?php

declare(strict_types=1);

namespace Afiphp;

use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\Preview;
use Afiphp\Billing\Taxpayer;
use Afiphp\Internal\Billing\FiscalGateway;
use Afiphp\Internal\Billing\InvoiceCalculator;
use Afiphp\Internal\Billing\IssuanceCoordinator;
use Afiphp\Internal\WebServices\PadronA13;
use Afiphp\Internal\WebServices\PadronA5;
use Afiphp\Internal\WebServices\WsFeV1;
use DateTimeImmutable;
use DateTimeZone;

/** Public entry point. No ARCA codes, SOAP requests, or authentication calls required. */
final class Arca
{
    private FiscalGateway $gateway;
    private IssuanceCoordinator $issuance;
    private PadronA5 $padron;
    private PadronA13 $documents;

    /**
     * @param FiscalGateway|null $gateway Internal testing seam, not an application integration API.
     * @param PadronA5|null $padron Internal testing seam, not an application integration API.
     * @param PadronA13|null $documents Internal testing seam, not an application integration API.
     */
    public function __construct(
        private readonly Configuration $configuration,
        ?FiscalGateway $gateway = null,
        ?PadronA5 $padron = null,
        ?PadronA13 $documents = null,
    ) {
        $this->gateway = $gateway ?? new WsFeV1($configuration);
        $this->issuance = new IssuanceCoordinator($configuration, $this->gateway);
        $this->padron = $padron ?? new PadronA5($configuration);
        $this->documents = $documents ?? new PadronA13($configuration);
    }

    /**
     * ARCA's registry (constancia de inscripción): name, fiscal address and VAT condition of a CUIT.
     * Null when the CUIT does not exist. Requires the certificate to be enabled for ws_sr_constancia_inscripcion.
     */
    public function taxpayer(string $cuit): ?Taxpayer
    {
        return $this->padron->taxpayer($cuit);
    }

    /**
     * The CUITs/CUILs ARCA's registry (Padrón A13) associates with a DNI; usually one, empty when none.
     * Requires the certificate to be enabled for ws_sr_padron_a13.
     * @return list<string>
     */
    public function cuitsByDni(string $dni): array
    {
        return $this->documents->cuitsByDni($dni);
    }

    /**
     * ARCA's dollar rate (FEParamGetCotizacion), ready for Currency::dollars(). A reference only:
     * the application still asserts the fiscal rate it invoices with.
     */
    public function dollarRate(?DateTimeImmutable $date = null): string
    {
        return $this->gateway->dollarRate($date);
    }

    /** Calculate and validate without contacting ARCA or reserving a number. */
    public function preview(Invoice $invoice): Preview
    {
        return InvoiceCalculator::calculate($invoice, $this->configuration->vatCondition);
    }

    /**
     * Pass a stable operation identifier (the sale, order or refund id) so a repeated call returns
     * the recorded result instead of issuing again. Without one, a random identifier is assigned
     * and returned in $result->operation: recovery still works, but a repeat issues a new voucher.
     */
    public function issue(Invoice $invoice, ?string $operation = null): IssueResult
    {
        return $this->issuance->issue($invoice, $operation);
    }

    /**
     * The note crediting an authorized operation in full: same items and other taxes. Receiver,
     * currency, service, sales point and class come from the stored original. Reads the local
     * journal only: nothing is sent or reserved, so pass the result to preview() or issue().
     */
    public function fullCreditNote(string $originalOperation, ?DateTimeImmutable $date = null): Invoice
    {
        return $this->note($originalOperation, InvoiceKind::CREDIT_NOTE, null, [], $date);
    }

    /**
     * A partial credit of an authorized operation; it must not exceed the original total.
     * Other taxes are never prorated: pass the ones that apply to this note.
     * @param list<Item> $items
     * @param list<OtherTax> $otherTaxes
     */
    public function creditNote(
        string $originalOperation,
        array $items,
        array $otherTaxes = [],
        ?DateTimeImmutable $date = null,
    ): Invoice {
        return $this->note($originalOperation, InvoiceKind::CREDIT_NOTE, $items, $otherTaxes, $date);
    }

    /**
     * A debit note on an authorized operation; not capped by the original total.
     * @param list<Item> $items
     * @param list<OtherTax> $otherTaxes
     */
    public function debitNote(
        string $originalOperation,
        array $items,
        array $otherTaxes = [],
        ?DateTimeImmutable $date = null,
    ): Invoice {
        return $this->note($originalOperation, InvoiceKind::DEBIT_NOTE, $items, $otherTaxes, $date);
    }

    /** Read-only at ARCA: reconcile the saved request against its exact reserved number. */
    public function recover(string $operation): IssueResult
    {
        return $this->issuance->recover($operation);
    }

    /** Explicit retry of the SAME request/number, only after a verified absence and sequence check. */
    public function retry(string $operation): IssueResult
    {
        return $this->issuance->retry($operation);
    }

    /** The date is part of the note: today in Buenos Aires unless given. */
    private function note(
        string $originalOperation,
        InvoiceKind $kind,
        ?array $items,
        array $otherTaxes,
        ?DateTimeImmutable $date,
    ): Invoice {
        $date ??= new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));

        return $this->issuance->noteFor($originalOperation, $kind, $items, $otherTaxes, $date);
    }
}
