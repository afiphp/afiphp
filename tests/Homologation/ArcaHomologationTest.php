<?php

declare(strict_types=1);

namespace Tests\Homologation;

use Afiphp\Arca;
use Afiphp\Billing\AssociatedPeriod;
use Afiphp\Billing\Currency;
use Afiphp\Billing\DocumentType;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Outcome;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Credentials;
use Afiphp\Environment;
use Afiphp\Exceptions\SoapException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\Billing\FiscalEvidence;
use Afiphp\Internal\Billing\InvoiceMapper;
use Afiphp\Internal\WebServices\WsFeV1;
use Afiphp\Internal\WebServices\WsResponse;
use Afiphp\NetworkOptions;
use Afiphp\Storage\FileStorage;
use Afiphp\Storage\IssuanceJournal;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real calls to ARCA's HOMOLOGATION environment (wswhomo / wsaahomo). Never production.
 *
 *   ARCA_CUIT=20XXXXXXXXX ARCA_CERT=/path/homo.crt ARCA_KEY=/path/homo.key ARCA_SALES_POINT=1 \
 *   [ARCA_VAT_CONDITION=RESPONSABLE_INSCRIPTO] composer test:arca
 *
 * Registry tests are skipped unless the certificate is also authorized for ws_sr_constancia_inscripcion
 * and ws_sr_padron_a13.
 *
 * The certificate must be a homologation certificate with the "wsfe" service delegated to ARCA_CUIT.
 * Tickets and journals live in ARCA_STORAGE (default: a private temp directory) so repeated runs reuse the TA.
 */
#[Group('arca')]
final class ArcaHomologationTest extends TestCase
{
    // Homologation's registry lacks most real CUITs and DNIs; these exist there (the DNI has two CUITs).
    private const string REGISTRY_CUIT = '30500010912';
    private const string REGISTRY_DNI = '20173159';

    private static ?Configuration $configuration = null;
    private static int $salesPoint;

    protected function setUp(): void
    {
        if (self::$configuration !== null) {
            return;
        }
        if (!extension_loaded('soap')) {
            self::markTestSkipped('ext-soap is required.');
        }
        $env = static fn (string $name): ?string => is_string(getenv($name)) && getenv($name) !== ''
            ? getenv($name)
            : null;
        if (
            $env('ARCA_CUIT') === null
            || $env('ARCA_CERT') === null
            || $env('ARCA_KEY') === null
            || $env('ARCA_SALES_POINT') === null
        ) {
            self::markTestSkipped(
                'Set ARCA_CUIT, ARCA_CERT, ARCA_KEY and ARCA_SALES_POINT to run the homologation suite.',
            );
        }
        self::$salesPoint = (int) $env('ARCA_SALES_POINT');
        $condition = constant(VatCondition::class . '::' . ($env('ARCA_VAT_CONDITION') ?? 'RESPONSABLE_INSCRIPTO'));
        $storage = $env('ARCA_STORAGE') ?? sys_get_temp_dir() . '/afiphp-homologation-' . $env('ARCA_CUIT');
        self::$configuration = new Configuration(
            $env('ARCA_CUIT'),
            $condition,
            new Credentials(
                (string) file_get_contents($env('ARCA_CERT')),
                (string) file_get_contents($env('ARCA_KEY')),
                $env('ARCA_KEY_PASSPHRASE') ?? '',
            ),
            new FileStorage($storage),
            Environment::HOMOLOGATION, // Hard-coded on purpose: this suite must never reach production.
            new NetworkOptions(saveSoapMessages: true),
        );
    }

    /** Detects catalog drift: every code the mapper can emit must exist in ARCA's parameter tables. */
    public function testEveryCodeWeEmitExistsInArcaCatalogs(): void
    {
        $ws = $this->wsfe();
        $ids = static fn (object $result, string $item): array => array_map(
            static fn (mixed $row): int => (int) WsResponse::field($row, 'Id'),
            WsResponse::items($result->{$item} ?? null),
        );

        $types = $ids($ws->execute('FEParamGetTiposCbte'), 'CbteTipo');
        foreach (InvoiceClass::cases() as $class) {
            foreach (InvoiceKind::cases() as $kind) {
                self::assertContains(InvoiceMapper::type($class, $kind), $types, "{$class->value} {$kind->value}");
            }
        }
        $rates = $ids($ws->execute('FEParamGetTiposIva'), 'IvaTipo');
        foreach ([
            VatRate::ZERO,
            VatRate::REDUCED_2_5,
            VatRate::REDUCED_5,
            VatRate::REDUCED_10_5,
            VatRate::STANDARD_21,
            VatRate::INCREASED_27,
        ] as $rate) {
            self::assertContains(InvoiceMapper::rate($rate), $rates, $rate->name);
        }
        $taxes = $ids($ws->execute('FEParamGetTiposTributos'), 'TributoTipo');
        foreach (OtherTaxType::cases() as $type) {
            self::assertContains(InvoiceMapper::taxType($type), $taxes, $type->name);
        }
        $conditions = $ids($ws->execute('FEParamGetCondicionIvaReceptor'), 'CondicionIvaReceptor');
        foreach (VatCondition::cases() as $condition) {
            self::assertContains(InvoiceMapper::condition($condition), $conditions, $condition->name);
        }
        $documents = $ids($ws->execute('FEParamGetTiposDoc'), 'DocTipo');
        foreach (DocumentType::cases() as $type) {
            self::assertContains(InvoiceMapper::documentType($type), $documents, $type->name);
        }
        try {
            $points = array_map(
                static fn (mixed $row): int => (int) WsResponse::field($row, 'Nro'),
                WsResponse::items($ws->execute('FEParamGetPtosVenta')->PtoVenta ?? null),
            );
        } catch (WsException $exception) {
            // ARCA reports an empty list as error 602 "Sin Resultados".
            if ($exception->getCode() !== 602) {
                throw $exception;
            }
            $points = [];
        }
        // Homologation often returns no sales points at all; production must list the configured one.
        if ($points !== []) {
            self::assertContains(self::$salesPoint, $points, 'ARCA_SALES_POINT is not enabled for web services.');
        }
    }

    public function testRegistryDescribesATaxpayer(): void
    {
        $taxpayer = self::registry('ws_sr_constancia_inscripcion', static fn (Arca $arca) => $arca->taxpayer(
            self::REGISTRY_CUIT,
        ));
        self::assertNotNull($taxpayer);
        self::assertSame(self::REGISTRY_CUIT, $taxpayer->cuit);
        self::assertNotNull($taxpayer->name);
    }

    public function testRegistryFindsTheCuitsOfADni(): void
    {
        $cuits = self::registry('ws_sr_padron_a13', static fn (Arca $arca) => $arca->cuitsByDni(self::REGISTRY_DNI));
        self::assertCount(2, $cuits);
        foreach ($cuits as $cuit) {
            self::assertStringContainsString(self::REGISTRY_DNI, $cuit);
        }
    }

    /**
     * Runs a registry query, skipping the test when the certificate lacks that service.
     *
     * @template T
     * @param callable(Arca): T $query
     * @return T
     */
    private static function registry(string $service, callable $query): mixed
    {
        try {
            return $query(new Arca(self::$configuration));
        } catch (SoapException $exception) {
            if ($exception->getFaultCode() !== 'ns1:coe.notAuthorized') {
                throw $exception;
            }
            self::markTestSkipped("The certificate is not authorized for {$service}.");
        }
    }

    public function testDollarRateIsUsableAsAnInvoiceCurrency(): void
    {
        $rate = (new Arca(self::$configuration))->dollarRate();
        self::assertSame($rate, Currency::dollars($rate, paidInDollars: false)->exchangeRate);
        self::assertGreaterThan(0, (float) $rate);
    }

    /** Full lifecycle: authorize, reconcile against FECompConsultar, replay, then a credit note. */
    public function testInvoiceIsAuthorizedReconciledAndCanBeCreditedInFull(): void
    {
        $arca = new Arca(self::$configuration);
        $invoice = $this->invoice(100000);
        $operation = 'homologation:' . bin2hex(random_bytes(8));

        $result = $arca->issue($invoice, $operation);
        self::assertSame(
            Outcome::AUTHORIZED,
            $result->outcome,
            $result->reason . ' ' . implode(' | ', $result->observations),
        );
        self::assertEquals($arca->preview($invoice), $result->totals);
        self::assertMatchesRegularExpression('/^[0-9]{14}$/', $result->authorization->cae);

        // The most valuable check: the real FECompConsultar answer must reconcile field by field
        // with what we sent. If ARCA omits or reformats a field, recovery would stay INDETERMINATE forever.
        $record = $this->record($operation);
        $found = $this->wsfe()->lookup(
            $record['request']['FeCAEReq']['FeCabReq']['CbteTipo'],
            $result->salesPoint,
            $result->number,
        );
        self::assertSame(
            [
                'status' => 'authorized',
                'cae' => $result->authorization->cae,
                'expires' => $result->authorization->expiresOn->format('Y-m-d'),
                'reconciled' => true,
            ],
            FiscalEvidence::lookup($found, $record['request']),
        );

        self::assertEquals($result, $arca->issue($invoice, $operation), 'A replay must return the stored result.');

        $credit = $arca->fullCreditNote($operation);
        self::assertEquals($result->invoiceReference(), $credit->original);
        $note = $arca->issue($credit, $operation . ':nc');
        self::assertAuthorized($note);
        self::assertSame($result->class, $note->class);
        self::assertEquals($result->totals->total, $note->totals->total);
    }

    /** Invoice to a registered business (class A for a registered issuer), then a partial credit and a debit. */
    public function testBusinessInvoiceTakesAPartialCreditAndADebitNote(): void
    {
        $arca = new Arca(self::$configuration);
        $receiver = Receiver::business(self::REGISTRY_CUIT, VatCondition::RESPONSABLE_INSCRIPTO);
        $operation = 'homologation:' . bin2hex(random_bytes(8));
        $result = $arca->issue($this->invoice(100000, receiver: $receiver), $operation);
        self::assertAuthorized($result);
        if (self::registered()) {
            self::assertSame(InvoiceClass::A, $result->class);
        }

        $credit = $arca->issue($arca->creditNote($operation, [self::item(5000)]), $operation . ':nc');
        self::assertAuthorized($credit);
        self::assertSame(InvoiceKind::CREDIT_NOTE, $credit->kind);
        self::assertSame($result->class, $credit->class);

        $debit = $arca->issue($arca->debitNote($operation, [self::item(1000)]), $operation . ':nd');
        self::assertAuthorized($debit);
        self::assertSame(InvoiceKind::DEBIT_NOTE, $debit->kind);
        self::assertSame($result->class, $debit->class);
    }

    /** A note by period (PeriodoAsoc) has no original voucher: ARCA must accept it and return the same period. */
    public function testCreditNoteForAnAssociatedPeriodIsAuthorizedAndReconciled(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $period = new AssociatedPeriod(
            $today->modify('first day of last month'),
            $today->modify('last day of last month'),
        );
        $template = $this->invoice(10000);
        $note = new Invoice(
            $template->salesPoint,
            $template->receiver,
            $template->items,
            $today,
            kind: InvoiceKind::CREDIT_NOTE,
            otherTaxes: $template->otherTaxes,
            period: $period,
        );
        $operation = 'homologation:' . bin2hex(random_bytes(8));

        $result = (new Arca(self::$configuration))->issue($note, $operation);
        self::assertSame(
            Outcome::AUTHORIZED,
            $result->outcome,
            $result->reason . ' ' . implode(' | ', $result->observations),
        );
        self::assertSame(InvoiceKind::CREDIT_NOTE, $result->kind);

        $record = $this->record($operation);
        $found = $this->wsfe()->lookup(
            $record['request']['FeCAEReq']['FeCabReq']['CbteTipo'],
            $result->salesPoint,
            $result->number,
        );
        $returned = $found->PeriodoAsoc ?? null;
        self::assertSame(
            [$period->from->format('Ymd'), $period->to->format('Ymd')],
            [WsResponse::field($returned, 'FchDesde'), WsResponse::field($returned, 'FchHasta')],
            'FECompConsultar must return the associated period that was sent.',
        );
        self::assertSame(
            [
                'status' => 'authorized',
                'cae' => $result->authorization->cae,
                'expires' => $result->authorization->expiresOn->format('Y-m-d'),
                'reconciled' => true,
            ],
            FiscalEvidence::lookup($found, $record['request']),
        );
    }

    public function testRejectedVoucherIsReportedAsRejectedWithObservations(): void
    {
        // A dollar invoice whose exchange rate is far from the official one is a deterministic, harmless rejection.
        $invoice = $this->invoice(1000, Currency::dollars('1', true));
        $result = (new Arca(self::$configuration))->issue($invoice, 'homologation:' . bin2hex(random_bytes(8)));
        self::assertSame(Outcome::REJECTED, $result->outcome);
        self::assertNotSame([], $result->observations);
        self::assertNull($result->authorization);
    }

    public function testDollarInvoiceAtTheOfficialRateIsAuthorizedAndReconciled(): void
    {
        $quote = $this->wsfe()->execute('FEParamGetCotizacion', ['MonId' => 'DOL']);
        $rate = (string) WsResponse::field($quote, 'MonCotiz');
        if (str_contains($rate, '.')) {
            [$whole, $fraction] = explode('.', $rate);
            $rate = $whole . '.' . substr($fraction, 0, 6);
        }
        $invoice = $this->invoice(1000, Currency::dollars($rate, false));
        $operation = 'homologation:' . bin2hex(random_bytes(8));
        $result = (new Arca(self::$configuration))->issue($invoice, $operation);
        self::assertSame(
            Outcome::AUTHORIZED,
            $result->outcome,
            $result->reason . ' ' . implode(' | ', $result->observations),
        );
        $record = $this->record($operation);
        $found = $this->wsfe()->lookup(
            $record['request']['FeCAEReq']['FeCabReq']['CbteTipo'],
            $result->salesPoint,
            $result->number,
        );
        self::assertSame('authorized', FiscalEvidence::lookup($found, $record['request'])['status']);
    }

    private static function assertAuthorized(IssueResult $result): void
    {
        self::assertSame(
            Outcome::AUTHORIZED,
            $result->outcome,
            $result->reason . ' ' . implode(' | ', $result->observations),
        );
    }

    private static function registered(): bool
    {
        return self::$configuration->vatCondition === VatCondition::RESPONSABLE_INSCRIPTO;
    }

    /** A single line that fits the issuer: taxed at 21% when registered, a plain amount otherwise. */
    private static function item(int $amount): Item
    {
        return self::registered() ? Item::netAmount($amount, VatRate::STANDARD_21) : Item::amount($amount);
    }

    private function invoice(int $amount, ?Currency $currency = null, ?Receiver $receiver = null): Invoice
    {
        $receiver ??= Receiver::finalConsumerWithDni('30111222');
        $items = self::registered()
            ? [
                Item::netAmount($amount, VatRate::STANDARD_21),
                Item::amount(12100, VatRate::REDUCED_10_5),
                Item::netAmount(5000, VatRate::EXEMPT),
            ]
            : [Item::amount($amount)];

        return new Invoice(
            self::$salesPoint,
            $receiver,
            $items,
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
            currency: $currency,
            otherTaxes: [new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'Percepcion IIBB', $amount, '2.50')],
        );
    }

    private function record(string $operation): array
    {
        $scope = 'wsfe:v1:homologation:' . self::$configuration->cuit;

        return self::$configuration->storage->exclusively(
            $scope,
            static fn (IssuanceJournal $journal): array => $journal->all()[hash('sha256', $operation)],
        );
    }

    private function wsfe(): WsFeV1
    {
        return new WsFeV1(self::$configuration);
    }
}
