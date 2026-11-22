<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\AssociatedPeriod;
use Afiphp\Billing\Currency;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Outcome;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Internal\Billing\InvoiceCodec;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\FakeFiscalGateway;

final class NotesTest extends TestCase
{
    private FakeFiscalGateway $gateway;
    private Arca $arca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeFiscalGateway();
        $this->arca = new Arca($this->configuration(), $this->gateway);
    }

    public function testStoredIntentRoundTripsThroughThePublicConstructors(): void
    {
        $date = new DateTimeImmutable('2026-11-14', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $invoices = [
            $this->invoice($date, Currency::dollars('1455.5', paidInDollars: true)),
            new Invoice(
                3,
                Receiver::finalConsumerWithCuil('20111111112'),
                [Item::amount(5000)],
                $date,
                kind: InvoiceKind::CREDIT_NOTE,
                period: new AssociatedPeriod($date->modify('-1 month'), $date->modify('-1 day')),
            ),
            new Invoice(
                4,
                Receiver::finalConsumerWithDni('30111222'),
                [Item::amount(1210, VatRate::REDUCED_10_5), Item::netAmount(10, VatRate::UNTAXED)],
                $date,
                kind: InvoiceKind::DEBIT_NOTE,
                original: new InvoiceReference(InvoiceClass::B, 4, 9, $date, InvoiceKind::CREDIT_NOTE),
            ),
        ];
        foreach ($invoices as $invoice) {
            $encoded = InvoiceCodec::encode($invoice);
            self::assertEquals($invoice, InvoiceCodec::decode($encoded));
            // The persisted form is plain JSON data.
            self::assertSame($encoded, json_decode(json_encode($encoded, JSON_THROW_ON_ERROR), true));
        }
    }

    public function testFullCreditNoteMirrorsTheOriginalExactly(): void
    {
        $original = $this->arca->issue($this->invoice($this->today(), Currency::pesos()), 'sale:1');
        $calls = count($this->gateway->calls);
        $invoice = $this->arca->fullCreditNote('sale:1');
        self::assertEquals($original->totals, $this->arca->preview($invoice), 'Previewed before issuing.');
        self::assertCount($calls, $this->gateway->calls, 'Deriving and previewing a note sends nothing.');
        $note = $this->arca->issue($invoice, 'sale:1:nc');

        self::assertSame(Outcome::AUTHORIZED, $note->outcome);
        self::assertSame(InvoiceKind::CREDIT_NOTE, $note->kind);
        self::assertSame($original->class, $note->class);
        self::assertEquals($original->totals, $note->totals);
        $sent = $this->lastRequest();
        self::assertSame(3, $sent['FeCAEReq']['FeCabReq']['CbteTipo']);
        $detail = $sent['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        self::assertSame(
            ['Tipo' => 1, 'PtoVta' => 2, 'Nro' => 50],
            array_intersect_key($detail['CbtesAsoc']['CbteAsoc'][0], ['Tipo' => 0, 'PtoVta' => 0, 'Nro' => 0]),
        );
        self::assertSame('20111111112', $detail['DocNro']);
        self::assertCount(1, $detail['Tributos']['Tributo']);
    }

    public function testPartialNotesTakeTheRestFromTheOriginal(): void
    {
        $this->arca->issue($this->invoice($this->today(), Currency::dollars('1455.5', paidInDollars: false)), 'sale:1');
        $credit = $this->arca->issue($this->arca->creditNote('sale:1', [Item::netAmount(
            1000,
            VatRate::STANDARD_21,
        )]), 'sale:1:nc');
        self::assertSame(Outcome::AUTHORIZED, $credit->outcome);
        self::assertSame(1210, $credit->totals->total);
        $detail = $this->lastRequest()['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        self::assertSame(['DOL', '1455.5'], [$detail['MonId'], $detail['MonCotiz']]);
        self::assertArrayNotHasKey('Tributos', $detail, 'Other taxes are never prorated.');

        // A debit note is not capped by the original total.
        $debit = $this->arca->issue($this->arca->debitNote('sale:1', [Item::netAmount(
            10_000_000,
            VatRate::STANDARD_21,
        )]), 'sale:1:nd');
        self::assertSame(Outcome::AUTHORIZED, $debit->outcome);
        self::assertSame(InvoiceKind::DEBIT_NOTE, $debit->kind);
    }

    public function testCreditNoteCannotExceedItsOriginalAndNothingIsSent(): void
    {
        $this->arca->issue($this->invoice($this->today(), Currency::pesos()), 'sale:1');
        $calls = count($this->gateway->calls);
        try {
            $this->arca->creditNote('sale:1', [Item::netAmount(1_000_000, VatRate::STANDARD_21)]);
            self::fail('Expected the credit note to exceed its original.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Nothing was sent', $exception->getMessage());
        }
        self::assertCount($calls, $this->gateway->calls);
    }

    public function testOnlyAnAuthorizedOperationCanBeAdjusted(): void
    {
        $this->gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $this->arca->issue($this->invoice($this->today(), Currency::pesos()), 'sale:1');
        foreach (['sale:1', 'sale:unknown'] as $original) {
            try {
                $this->arca->fullCreditNote($original);
                self::fail('Expected no note for ' . $original);
            } catch (InvalidArgumentException) {
                self::assertSame(['last', 'authorize'], array_column($this->gateway->calls, 0));
            }
        }
    }

    public function testALaterNoteRaisesAnExpiredPaymentDueDate(): void
    {
        $original = $this->today()->modify('-3 days');
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(10000, VatRate::STANDARD_21)],
            $original,
            new ServicePeriod($original->modify('-1 month'), $original, $original),
        );
        $this->arca->issue($invoice, 'service:1');
        $this->arca->issue($this->arca->fullCreditNote('service:1'), 'service:1:nc');
        $detail = $this->lastRequest()['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        self::assertSame($this->today()->format('Ymd'), $detail['FchVtoPago']);
        self::assertSame($original->format('Ymd'), $detail['FchServHasta']);
    }

    private function invoice(DateTimeImmutable $date, Currency $currency): Invoice
    {
        return new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [
                Item::netAmount(10000, VatRate::STANDARD_21),
                Item::amount(1105, VatRate::REDUCED_10_5),
                Item::netAmount(500, VatRate::EXEMPT),
            ],
            $date,
            currency: $currency,
            otherTaxes: [new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'IIBB', 10000, '2.5')],
        );
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    private function lastRequest(): array
    {
        $authorizations = array_values(array_filter(
            $this->gateway->calls,
            static fn (array $call): bool => $call[0] === 'authorize',
        ));

        return end($authorizations)[1];
    }
}
