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
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Credentials;
use Afiphp\Environment;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\InvoiceRejected;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\Billing\InvoiceMapper;
use Afiphp\Internal\WebServices\WsFeV1;
use Afiphp\Internal\WebServices\WsResponse;
use Afiphp\Storage\FileStorage;
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
 * Tickets live in ARCA_STORAGE (default: a private temp directory) so repeated runs reuse the TA.
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
                self::assertContains(InvoiceMapper::type($class, $kind), $types, "{$class->value} {$kind->name}");
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
        } catch (ConfigurationError $exception) {
            if (!str_contains($exception->getMessage(), 'coe.notAuthorized')) {
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

    /**
     * Full lifecycle: authorize, find it again as an earlier attempt, then a credit note.
     * The lookup is the most valuable check: the real FECompConsultar answer must match what we sent
     * field by field. If ARCA omits or reformats a field, a lost answer could never be confirmed.
     */
    public function testInvoiceIsAuthorizedFoundAgainAndCanBeCreditedInFull(): void
    {
        $arca = new Arca(self::$configuration);
        $invoice = $this->invoice(100000);

        $result = $arca->issue($invoice);
        self::assertEquals($arca->preview($invoice), $result->totals);
        self::assertMatchesRegularExpression('/^[0-9]{14}$/', $result->authorization->cae);
        self::assertFoundAgain($arca, $invoice, $result);

        // The public lookup reads back the same voucher, and the full credit note mirrors it exactly.
        $found = $arca->voucher($result->class, $result->salesPoint, $result->number);
        self::assertNotNull($found);
        self::assertEquals($result->totals, $found->totals);
        self::assertEquals($result->receiver, $found->receiver);
        self::assertSame($result->qr, $found->qr);
        self::assertSame($result->number, $arca->lastNumber($result->salesPoint, $result->class));

        $full = $arca->fullCreditNote($result->invoiceReference(), $invoice->date);
        self::assertEquals($arca->preview($invoice), $arca->preview($full));
        $note = $arca->issue($full);
        self::assertSame($result->class, $note->class);
        self::assertEquals($result->totals->total, $note->totals->total);
    }

    /** check() logs in and accepts the configured sales point, which salesPoints() lists unless homologation lists none. */
    public function testCheckAcceptsTheConfiguredSalesPoint(): void
    {
        $arca = new Arca(self::$configuration);
        $arca->check(self::$salesPoint);
        // check() returning without an exception is the assertion.
        $this->addToAssertionCount(1);
        $points = $arca->salesPoints();
        if ($points !== []) {
            self::assertContains(self::$salesPoint, array_map(static fn ($point): int => $point->number, $points));
        }
    }

    /** Invoice to a registered business (class A for a registered issuer), then a partial credit and a debit. */
    public function testBusinessInvoiceTakesAPartialCreditAndADebitNote(): void
    {
        $arca = new Arca(self::$configuration);
        $receiver = Receiver::business(self::REGISTRY_CUIT, VatCondition::RESPONSABLE_INSCRIPTO);
        $invoice = $this->invoice(100000, receiver: $receiver);
        $result = $arca->issue($invoice);
        if (self::registered()) {
            self::assertSame(InvoiceClass::A, $result->class);
        }

        $credit = $arca->issue($this->note($invoice, InvoiceKind::CREDIT_NOTE, [self::item(5000)], $result));
        self::assertSame(InvoiceKind::CREDIT_NOTE, $credit->kind);
        self::assertSame($result->class, $credit->class);

        $debit = $arca->issue($this->note($invoice, InvoiceKind::DEBIT_NOTE, [self::item(1000)], $result));
        self::assertSame(InvoiceKind::DEBIT_NOTE, $debit->kind);
        self::assertSame($result->class, $debit->class);
    }

    /** A note by period (PeriodoAsoc) has no original voucher: ARCA must accept it and return the same period. */
    public function testCreditNoteForAnAssociatedPeriodIsAuthorizedAndFoundAgain(): void
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

        $arca = new Arca(self::$configuration);
        $result = $arca->issue($note);
        self::assertSame(InvoiceKind::CREDIT_NOTE, $result->kind);

        $found = $this->wsfe()->lookup(
            InvoiceMapper::type($result->class, $result->kind),
            $result->salesPoint,
            $result->number,
        );
        $returned = $found->PeriodoAsoc ?? null;
        self::assertSame(
            [$period->from->format('Ymd'), $period->to->format('Ymd')],
            [WsResponse::field($returned, 'FchDesde'), WsResponse::field($returned, 'FchHasta')],
            'FECompConsultar must return the associated period that was sent.',
        );
        self::assertFoundAgain($arca, $note, $result);
    }

    public function testRejectedVoucherIsReportedAsRejectedWithObservations(): void
    {
        // A dollar invoice collected in pesos with a rate far from the official one is a deterministic, harmless
        // rejection (10119). Collected in dollars, homologation does not check the rate and authorizes it.
        $invoice = $this->invoice(1000, Currency::dollars('1', false));
        try {
            (new Arca(self::$configuration))->issue($invoice);
            self::fail('Expected a rejection.');
        } catch (InvoiceRejected $exception) {
            self::assertNotSame([], $exception->observations);
        }
    }

    public function testDollarInvoiceAtTheOfficialRateIsAuthorizedAndFoundAgain(): void
    {
        $quote = $this->wsfe()->execute('FEParamGetCotizacion', ['MonId' => 'DOL']);
        $rate = (string) WsResponse::field($quote, 'MonCotiz');
        if (str_contains($rate, '.')) {
            [$whole, $fraction] = explode('.', $rate);
            $rate = $whole . '.' . substr($fraction, 0, 6);
        }
        $arca = new Arca(self::$configuration);
        $invoice = $this->invoice(1000, Currency::dollars($rate, false));
        $result = $arca->issue($invoice);
        self::assertFoundAgain($arca, $invoice, $result);
    }

    /** An unconfirmed attempt that never reached ARCA: the retry sends with that same number. */
    public function testRetryOfTheNextNumberSendsWithThatNumber(): void
    {
        $arca = new Arca(self::$configuration);
        $invoice = $this->invoice(2000);
        $class = $arca->preview($invoice)->class;
        $next = $arca->lastNumber(self::$salesPoint, $class) + 1;

        $result = $arca->retryIssue($invoice, unconfirmedNumber: $next);
        self::assertSame($next, $result->number);
        self::assertMatchesRegularExpression('/^[0-9]{14}$/', $result->authorization->cae);
        self::assertSame($next, $arca->lastNumber(self::$salesPoint, $class));
    }

    /** Another voucher holds the unconfirmed number: the earlier attempt never landed, so it is issued as new. */
    public function testRetryOfANumberHeldByAnotherVoucherIssuesANewOne(): void
    {
        $arca = new Arca(self::$configuration);
        $other = $arca->issue($this->invoice(3000));
        $invoice = $this->invoice(4000);

        $result = $arca->retryIssue($invoice, unconfirmedNumber: $other->number);
        self::assertGreaterThan($other->number, $result->number);
        self::assertNotSame($other->authorization->cae, $result->authorization->cae);
        self::assertEquals($arca->preview($invoice), $result->totals);
    }

    /** Passing the number as an earlier attempt must find the voucher in ARCA instead of issuing another. */
    private static function assertFoundAgain(Arca $arca, Invoice $invoice, IssueResult $result): void
    {
        $again = $arca->retryIssue($invoice, unconfirmedNumber: $result->number);
        self::assertSame($result->number, $again->number);
        self::assertEquals($result->authorization, $again->authorization);
        self::assertSame($result->qr, $again->qr);
    }

    /** @param list<Item> $items */
    private function note(Invoice $original, InvoiceKind $kind, array $items, IssueResult $result): Invoice
    {
        return new Invoice(
            $original->salesPoint,
            $original->receiver,
            $items,
            $original->date,
            currency: $original->currency,
            kind: $kind,
            original: $result->invoiceReference(),
            otherTaxes: $items === $original->items ? $original->otherTaxes : [],
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

    private function wsfe(): WsFeV1
    {
        return new WsFeV1(self::$configuration);
    }
}
