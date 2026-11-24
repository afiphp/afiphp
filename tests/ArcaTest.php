<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\AssociatedPeriod;
use Afiphp\Billing\Currency;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Outcome;
use Afiphp\Billing\QrCode;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Environment;
use Afiphp\Exceptions\IdempotencyConflict;
use Afiphp\Exceptions\WsException;
use Afiphp\Storage\IssuanceJournal;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFiscalGateway;

final class ArcaTest extends TestCase
{
    private function client(
        FakeFiscalGateway $gateway,
        VatCondition $condition = VatCondition::RESPONSABLE_INSCRIPTO,
        Environment $environment = Environment::HOMOLOGATION,
    ): Arca {
        return new Arca(
            new Configuration('20111111112', $condition, $this->identity(), $this->storage(), $environment),
            $gateway,
        );
    }

    private function intent(int $net = 15000): Invoice
    {
        return new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount($net, VatRate::STANDARD_21)],
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
        );
    }

    public function testPreviewHasNoSideEffectsAndIssuanceOwnsNumberingAndExactRequest(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        self::assertSame(18150, $client->preview($this->intent())->total);
        self::assertSame([], $gateway->calls);
        $result = $client->issue($this->intent(), 'sale:1');
        self::assertSame(Outcome::AUTHORIZED, $result->outcome);
        self::assertSame(50, $result->number);
        self::assertSame('71372966559657', $result->authorization->cae);
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
        self::assertSame('181.50', $gateway->calls[1][1]['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0]['ImpTotal']);
        self::assertSame(50, $result->invoiceReference()->number);
    }

    public function testResultCarriesTheSameTotalsAsThePreviewEvenAfterRestart(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [
                Item::netAmount(10000, VatRate::STANDARD_21),
                Item::amount(1105, VatRate::REDUCED_10_5),
                Item::netAmount(500, VatRate::EXEMPT),
                Item::netAmount(300, VatRate::UNTAXED),
            ],
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
            otherTaxes: [new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'IIBB', 10000, '2.5')],
        );
        $client = $this->client(new FakeFiscalGateway());
        $preview = $client->preview($invoice);
        self::assertEquals($preview, $client->issue($invoice, 'sale:totals')->totals);
        $restarted = $this->client(new FakeFiscalGateway());
        self::assertEquals($preview, $restarted->issue($invoice, 'sale:totals')->totals);
        self::assertCount(2, $preview->vatByRate);
    }

    public function testAuthorizedResultCarriesTheRg4892QrPayload(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        $result = $client->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('30111222'),
                [Item::netAmount(15000, VatRate::STANDARD_21)],
                new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
                currency: Currency::dollars('1455.50', paidInDollars: false),
            ),
            'sale:qr',
        );
        self::assertSame(Outcome::AUTHORIZED, $result->outcome);
        self::assertStringStartsWith('https://www.arca.gob.ar/fe/qr/?p=', $result->qr);
        $json = base64_decode(substr($result->qr, strlen('https://www.arca.gob.ar/fe/qr/?p=')), true);
        self::assertSame(
            '{"ver":1,"fecha":"'
            . $result->date->format('Y-m-d')
            . '","cuit":20111111112,"ptoVta":2,'
            . '"tipoCmp":6,"nroCmp":50,"importe":181.50,"moneda":"DOL","ctz":1455.5,'
            . '"tipoDocRec":96,"nroDocRec":30111222,"tipoCodAut":"E","codAut":71372966559657}',
            $json,
        );
        self::assertIsArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(
            $result->qr,
            $this->client(new FakeFiscalGateway())->issue(
                new Invoice(
                    2,
                    Receiver::finalConsumerWithDni('30111222'),
                    [Item::netAmount(15000, VatRate::STANDARD_21)],
                    $result->date,
                    currency: Currency::dollars('1455.50', paidInDollars: false),
                ),
                'sale:qr',
            )->qr,
        );
    }

    public function testQrOfAnEarlierVoucherMatchesTheIssuedOne(): void
    {
        $receiver = Receiver::finalConsumerWithDni('30111222');
        $currency = Currency::dollars('1455.50', paidInDollars: false);
        $result = $this->client(new FakeFiscalGateway())->issue(
            new Invoice(
                2,
                $receiver,
                [Item::netAmount(15000, VatRate::STANDARD_21)],
                new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
                currency: $currency,
            ),
            'sale:qr',
        );
        $voucher = $result->invoiceReference();
        $cae = $result->authorization->cae;
        self::assertSame(
            $result->qr,
            QrCode::url('20111111112', $voucher, $result->totals->total, $cae, $receiver, $currency),
        );
        $withoutReceiver = QrCode::url('20111111112', $voucher, 18150, $cae);
        self::assertSame(
            '{"ver":1,"fecha":"'
            . $voucher->date->format('Y-m-d')
            . '","cuit":20111111112,"ptoVta":2,'
            . '"tipoCmp":6,"nroCmp":50,"importe":181.50,"moneda":"PES","ctz":1,'
            . '"tipoCodAut":"E","codAut":71372966559657}',
            base64_decode(substr($withoutReceiver, strlen('https://www.arca.gob.ar/fe/qr/?p=')), true),
        );
    }

    public function testOnlyAuthorizedResultsHaveAQr(): void
    {
        [$client] = $this->pending();
        self::assertNull($client->issue($this->intent(), 'sale:1')->qr);
    }

    public function testWithoutAnOperationEachCallIsANewVoucherThatCanStillBeRecovered(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        $first = $client->issue($this->intent());
        $gateway->last = 50;
        $second = $client->issue($this->intent());

        self::assertSame([Outcome::AUTHORIZED, Outcome::AUTHORIZED], [$first->outcome, $second->outcome]);
        self::assertSame([50, 51], [$first->number, $second->number]);
        self::assertMatchesRegularExpression('/^auto:[0-9a-f]{32}$/D', $first->operation);
        self::assertNotSame($first->operation, $second->operation);
        self::assertEquals($first, $client->recover($first->operation));
        self::assertSame(Outcome::AUTHORIZED, $client->issue($client->fullCreditNote($first->operation))->outcome);
    }

    public function testReplayAfterReconstructingClientReturnsSavedAuthorizationWithoutNetwork(): void
    {
        $gateway = new FakeFiscalGateway();
        $first = $this->client($gateway)->issue($this->intent(), 'sale:1');
        $restarted = new FakeFiscalGateway();
        $again = $this->client($restarted)->issue($this->intent(), 'sale:1');
        self::assertEquals($first, $again);
        self::assertSame([], $restarted->calls);
        self::assertEquals($again, $this->client($restarted)->recover('sale:1'));
        self::assertEquals($again, $this->client($restarted)->retry('sale:1'));
        self::assertSame([], $restarted->calls);
    }

    public function testTimeAndTimezoneDoNotChangeThePersistedFiscalIdentity(): void
    {
        $day = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $intent = $this->intent();
        $invoice = new Invoice($intent->salesPoint, $intent->receiver, $intent->items, $day->setTime(23, 59));
        $client = $this->client(new FakeFiscalGateway());
        $first = $client->issue($invoice, 'sale:calendar-day');
        self::assertSame(18150, $first->totals->total);
        self::assertSame($day->format('Y-m-d'), $first->date->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $first->authorization->expiresOn);

        $gateway = new FakeFiscalGateway();
        $replay = new Invoice(
            $intent->salesPoint,
            $intent->receiver,
            $intent->items,
            new DateTimeImmutable($day->format('Y-m-d') . 'T00:01:00+14:00'),
        );
        self::assertEquals($first, $this->client($gateway)->issue($replay, 'sale:calendar-day'));
        self::assertSame([], $gateway->calls);
    }

    #[DataProvider('persistedIssuerIdentities')]
    public function testRenamingPublicConditionsDoesNotChangePersistedOperationIdentity(
        VatCondition $issuer,
        string $persistedToken,
    ): void {
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [
                $issuer === VatCondition::RESPONSABLE_INSCRIPTO
                    ? Item::netAmount(15000, VatRate::STANDARD_21)
                    : Item::amount(15000),
            ],
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
        );
        $result = $this->client(new FakeFiscalGateway(), $issuer)->issue($invoice, 'existing-sale');
        $saved = json_decode(
            file_get_contents(glob($this->directory . '/storage/issuance/*.json')[0]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $record = $saved['records'][hash('sha256', 'existing-sale')];
        $detail = $record['request']['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        unset($detail['CbteDesde'], $detail['CbteHasta']);
        $existingFingerprint = hash('sha256', json_encode([
            'issuerCondition' => $persistedToken,
            'series' => $record['series'],
            'detail' => $detail,
        ], JSON_THROW_ON_ERROR));
        self::assertSame($existingFingerprint, $record['fingerprint']);
        $restarted = new FakeFiscalGateway();
        self::assertEquals($result, $this->client($restarted, $issuer)->issue($invoice, 'existing-sale'));
        self::assertSame([], $restarted->calls);
    }

    public static function persistedIssuerIdentities(): array
    {
        return [
            [VatCondition::RESPONSABLE_INSCRIPTO,                          'REGISTERED'],
            [VatCondition::MONOTRIBUTISTA,                                 'MONOTAX'],
            [VatCondition::MONOTRIBUTISTA_SOCIAL,                          'SOCIAL_MONOTAX'],
            [VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO, 'PROMOTED_MONOTAX'],
            [VatCondition::EXENTO,                                         'EXEMPT'],
            [VatCondition::NO_ALCANZADO,                                   'NOT_SUBJECT'],
        ];
    }

    public function testReusingOperationForDifferentMoneyThrowsBeforeAnyNetwork(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        $client->issue($this->intent(), 'sale:1');
        $gateway->calls = [];
        try {
            $client->issue($this->intent(15100), 'sale:1');
            self::fail('Expected idempotency conflict.');
        } catch (IdempotencyConflict) {
            self::assertSame([], $gateway->calls);
        }
    }

    public function testARejectedOperationCanBeIssuedAgainWithCorrectedData(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static function (array $request): object {
            $response = FakeFiscalGateway::approved($request);
            $response->FeCabResp->Resultado = 'R';
            $detail = $response->FeDetResp->FECAEDetResponse[0];
            $detail->Resultado = 'R';
            $detail->CAE = '';
            $detail->CAEFchVto = '';
            return $response;
        };
        $client = $this->client($gateway);
        self::assertSame(Outcome::REJECTED, $client->issue($this->intent(), 'sale:1')->outcome);
        $gateway->onAuthorize = null;
        $gateway->calls = [];
        self::assertSame(Outcome::REJECTED, $client->issue($this->intent(), 'sale:1')->outcome);
        self::assertSame([], $gateway->calls);
        $corrected = $client->issue($this->intent(15100), 'sale:1');
        self::assertSame(Outcome::AUTHORIZED, $corrected->outcome);
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
        self::assertEquals($corrected, $client->issue($this->intent(15100), 'sale:1'));
        $this->expectException(IdempotencyConflict::class);
        $client->issue($this->intent(), 'sale:1');
    }

    public function testAPendingOperationCannotTakeDifferentData(): void
    {
        [$client, $gateway] = $this->pending();
        $gateway->calls = [];
        try {
            $client->issue($this->intent(15100), 'sale:1');
            self::fail('Expected idempotency conflict.');
        } catch (IdempotencyConflict) {
            self::assertSame([], $gateway->calls);
        }
    }

    public function testDurableClaimExistsBeforeTheFirstSubmissionAndTimeoutBlocksSequence(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = function (): object {
            $files = glob($this->directory . '/storage/issuance/*.json');
            $records = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR)['records'];
            self::assertSame('pending', array_values($records)[0]['status']);
            self::assertSame(50, array_values($records)[0]['number']);
            throw new \RuntimeException('Timeout after send');
        };
        $client = $this->client($gateway);
        self::assertSame(Outcome::INDETERMINATE, $client->issue($this->intent(), 'sale:1')->outcome);
        $gateway->calls = [];
        self::assertSame(Outcome::INDETERMINATE, $client->issue($this->intent(), 'sale:1')->outcome);
        $blocked = $client->issue($this->intent(), 'sale:2');
        self::assertSame(Outcome::BLOCKED, $blocked->outcome);
        self::assertSame('sale:1', $blocked->blockedBy);
        self::assertNull($blocked->number);
        self::assertSame([], $gateway->calls);
    }

    public function testRecoveryRequiresCompleteFiscalMatchAndDoesNotResubmit(): void
    {
        [$client, $gateway, $request] = $this->pending();
        $gateway->onLookup = static fn (): object => FakeFiscalGateway::found($request);
        $gateway->calls = [];
        $recovered = $client->recover('sale:1');
        self::assertSame(Outcome::AUTHORIZED, $recovered->outcome);
        self::assertTrue($recovered->reconciled);
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
        self::assertSame(50, $recovered->number);
        self::assertEquals($recovered, $client->issue($this->intent(), 'sale:1'));
    }

    #[DataProvider('badLookup')]
    public function testLookupCannotAuthorizeOnNumberAlone(string $case, Outcome $expected): void
    {
        [$client, $gateway, $request] = $this->pending();
        $found = FakeFiscalGateway::found($request);
        match ($case) {
            'amount' => $found->ImpTotal = '181.51',
            'receiver' => $found->DocNro = '20999999999',
            'missing-condition' => $found->CondicionIVAReceptorId = null,
            'missing-vat' => $found->Iva = null,
            'vat' => $found->Iva->AlicIva[0]->Importe = '31.51',
            'cae' => $found->CodAutorizacion = 'invalid',
            'range' => $found->CbteHasta = 51,
            'decimal' => $found->ImpNeto = '150.001',
            'emission' => $found->EmisionTipo = 'CAEA',
            'integer-coercion' => $found->DocTipo = '80oops',
        };
        $gateway->onLookup = static fn (): object => $found;
        self::assertSame($expected, $client->recover('sale:1')->outcome);
        if ($expected === Outcome::INDETERMINATE) {
            self::assertSame(Outcome::BLOCKED, $client->issue($this->intent(), 'sale:2')->outcome);
            return;
        }
        // A conflict is final and does not block: the series continues after the occupied number...
        try {
            $client->issue($this->intent(), 'sale:2');
            self::fail('A sequence behind the conflicting voucher must not reuse its number.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Nothing was sent', $exception->getMessage());
        }
        // ...once ARCA's sequence includes it.
        $gateway->last = 50;
        $gateway->onAuthorize = null;
        $next = $client->issue($this->intent(), 'sale:2');
        self::assertSame(Outcome::AUTHORIZED, $next->outcome);
        self::assertSame(51, $next->number);
    }

    public static function badLookup(): array
    {
        return [
            ['amount',            Outcome::CONFLICT],
            ['receiver',          Outcome::CONFLICT],
            ['missing-condition', Outcome::INDETERMINATE],
            ['missing-vat',       Outcome::INDETERMINATE],
            ['vat',               Outcome::CONFLICT],
            ['cae',               Outcome::INDETERMINATE],
            ['range',             Outcome::CONFLICT],
            ['decimal',           Outcome::INDETERMINATE],
            ['emission',          Outcome::INDETERMINATE],
            ['integer-coercion',  Outcome::INDETERMINATE],
        ];
    }

    #[DataProvider('periodLookup')]
    public function testAssociatedPeriodTakesPartInReconciliation(?array $period, Outcome $expected): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $client = $this->client($gateway);
        $today = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $note = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(1000, VatRate::STANDARD_21)],
            $today,
            kind: InvoiceKind::CREDIT_NOTE,
            period: new AssociatedPeriod($today->modify('first day of last month'), $today->modify('-1 day')),
        );
        $client->issue($note, 'note:period');
        $request = $gateway->calls[1][1];
        $found = FakeFiscalGateway::found($request);
        if ($period !== []) {
            $found->PeriodoAsoc = $period === null ? null : (object) $period;
        }
        $gateway->onLookup = static fn (): object => $found;
        self::assertSame($expected, $client->recover('note:period')->outcome);
    }

    public static function periodLookup(): array
    {
        return [
            'same period' => [[], Outcome::AUTHORIZED],
            'other period' => [['FchDesde' => '20000101', 'FchHasta' => '20000131'], Outcome::CONFLICT],
            'period omitted' => [null, Outcome::INDETERMINATE],
            'malformed period' => [['FchDesde' => '2000-01-01', 'FchHasta' => '20000131'], Outcome::INDETERMINATE],
        ];
    }

    /** An empty period is "none" for an invoice; a real one means a different voucher. */
    #[DataProvider('periodOnInvoice')]
    public function testUnexpectedAssociatedPeriodIsNotTheSameInvoice(array $period, Outcome $expected): void
    {
        [$client, $gateway, $request] = $this->pending();
        $found = FakeFiscalGateway::found($request);
        $found->PeriodoAsoc = (object) $period;
        $gateway->onLookup = static fn (): object => $found;
        self::assertSame($expected, $client->recover('sale:1')->outcome);
    }

    public static function periodOnInvoice(): array
    {
        return [
            'empty' => [['FchDesde' => '', 'FchHasta' => ''], Outcome::AUTHORIZED],
            'real' => [['FchDesde' => '20260101', 'FchHasta' => '20260131'], Outcome::CONFLICT],
        ];
    }

    public function testOnlyExplicitRetryAfterVerifiedAbsenceMaySubmitExactSameNumberAgain(): void
    {
        [$client, $gateway, $request] = $this->pending();
        $gateway->onLookup = static fn (): object => throw self::notFound();
        $gateway->calls = [];
        self::assertSame(Outcome::INDETERMINATE, $client->recover('sale:1')->outcome);
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
        $gateway->calls = [];
        $gateway->onAuthorize = null;
        self::assertSame(Outcome::AUTHORIZED, $client->retry('sale:1')->outcome);
        self::assertSame(['lookup', 'last', 'authorize'], array_column($gateway->calls, 0));
        self::assertSame($request, $gateway->calls[2][1]);
    }

    public function testRetryNeverWritesWhenTheSequenceHasMoved(): void
    {
        [$client, $gateway] = $this->pending();
        $gateway->last = 50;
        $gateway->onAuthorize = null;
        $gateway->onLookup = static fn (): object => throw self::notFound();
        $gateway->calls = [];
        $result = $client->retry('sale:1');
        self::assertSame(Outcome::INDETERMINATE, $result->outcome);
        self::assertSame('retry_sequence_mismatch', $result->reason);
        self::assertSame(['lookup', 'last'], array_column($gateway->calls, 0));
    }

    public function testARejectedRetryCannotDisproveAnEarlierTimedOutAuthorization(): void
    {
        [$client, $gateway] = $this->pending();
        $gateway->onLookup = static fn (): object => throw self::notFound();
        $gateway->onAuthorize = static function (array $request): object {
            $response = FakeFiscalGateway::approved($request);
            $response->FeCabResp->Resultado = 'R';
            $detail = $response->FeDetResp->FECAEDetResponse[0];
            $detail->Resultado = 'R';
            $detail->CAE = '';
            $detail->CAEFchVto = '';
            $detail->Observaciones = (object) ['Obs' => (object) [
                'Code' => 10016,
                'Msg' => 'Numero ya utilizado',
            ]];
            return $response;
        };
        $result = $client->retry('sale:1');
        self::assertSame(Outcome::INDETERMINATE, $result->outcome);
        self::assertSame('retry_rejected_requires_reconciliation', $result->reason);
        self::assertSame(Outcome::BLOCKED, $client->issue($this->intent(), 'sale:2')->outcome);
    }

    public function testIncompleteOrFailedConsultationNeverPermitsARetry(): void
    {
        [$client, $gateway] = $this->pending();
        $gateway->calls = [];
        $gateway->onLookup = static fn (): object => throw new \RuntimeException('Unavailable');
        self::assertSame(Outcome::INDETERMINATE, $client->retry('sale:1')->outcome);
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
    }

    #[DataProvider('badAuthorization')]
    public function testContradictoryOrMalformedEvidenceCannotBecomeAuthorizedOrRejected(string $case): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static function (array $request) use ($case): object {
            $response = FakeFiscalGateway::approved($request);
            $detail = $response->FeDetResp->FECAEDetResponse[0];
            match ($case) {
                'header' => $response->FeCabResp->Resultado = 'R',
                'header-type' => $response->FeCabResp->CbteTipo = '1junk',
                'cuit' => $response->FeCabResp->Cuit = '20999999999',
                'count' => $response->FeCabResp->CantReg = 2,
                'missing-header' => $response->FeCabResp = null,
                'number' => $detail->CbteDesde = 51,
                'cae' => $detail->CAE = '00000000000000',
                'missing-cae' => $detail->CAE = null,
                'expiry' => $detail->CAEFchVto = '20260230',
                'partial' => $response->FeCabResp->Resultado = 'P',
                'error' => $response->Errors = (object) ['Err' => [(object) [
                    'Code' => 100,
                    'Msg' => 'Error',
                ]]],
                'observations' => $detail->Observaciones = (object) ['Obs' => (object) [
                    'Code' => 1,
                    'Msg' => (object) [],
                ]],
                'duplicate' => $response->FeDetResp->FECAEDetResponse[] = clone $detail,
            };
            return $response;
        };
        self::assertSame(Outcome::INDETERMINATE, $this->client($gateway)->issue($this->intent(), 'sale:1')->outcome);
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
    }

    public static function badAuthorization(): array
    {
        return array_map(static fn ($case): array => [$case], [
            'header',
            'header-type',
            'cuit',
            'count',
            'missing-header',
            'number',
            'cae',
            'missing-cae',
            'expiry',
            'partial',
            'error',
            'observations',
            'duplicate',
        ]);
    }

    public function testExplicitRejectionIsRememberedAndKeepsObservations(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static function (array $request): object {
            $response = FakeFiscalGateway::approved($request);
            $response->FeCabResp->Resultado = 'R';
            $detail = $response->FeDetResp->FECAEDetResponse[0];
            $detail->Resultado = 'R';
            $detail->CAE = '';
            $detail->CAEFchVto = '';
            $detail->Observaciones = (object) ['Obs' => (object) [
                'Code' => 100,
                'Msg' => 'Rechazado',
            ]];
            return $response;
        };
        $client = $this->client($gateway);
        $result = $client->issue($this->intent(), 'sale:1');
        self::assertSame(Outcome::REJECTED, $result->outcome);
        self::assertNull($result->authorization);
        self::assertSame(['(100) Rechazado'], $result->observations);
        $gateway->calls = [];
        self::assertEquals($result, $client->retry('sale:1'));
        self::assertSame([], $gateway->calls);
    }

    public function testDateValidationPrecedesNetworkAndReservation(): void
    {
        $gateway = new FakeFiscalGateway();
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(10000, VatRate::STANDARD_21)],
            new DateTimeImmutable('2000-01-01'),
        );
        try {
            $this->client($gateway)->issue($invoice, 'sale:1');
            self::fail('Expected invalid date.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $gateway->calls);
            self::assertSame([], glob($this->directory . '/storage/issuance/*.json'));
        }
    }

    public function testCorruptedJournalNeverBecomesAnEmptyStore(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        $client->issue($this->intent(), 'sale:1');
        file_put_contents(glob($this->directory . '/storage/issuance/*.json')[0], '{"version":1,"records":{"bad":{}}}');
        $gateway->calls = [];
        try {
            $client->issue($this->intent(), 'sale:2');
            self::fail('Expected corruption failure.');
        } catch (\RuntimeException) {
            self::assertSame([], $gateway->calls);
        }
    }

    public function testStoreCommitsAreNotRolledBackWhenCallbackFails(): void
    {
        $store = $this->storage();
        $this->client(new FakeFiscalGateway())->issue($this->intent(), 'x');
        $saved = json_decode(
            file_get_contents(glob($this->directory . '/storage/issuance/*.json')[0]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $record = $saved['records'][hash('sha256', 'x')];
        $record['status'] = 'pending';
        try {
            $store->exclusively('scope', static function (IssuanceJournal $journal) use ($record): void {
                $journal->save(hash('sha256', 'x'), $record);
                throw new \RuntimeException('Crash after durable intent');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($record, $store->exclusively(
            'scope',
            static fn (IssuanceJournal $journal): array => $journal->all()[hash('sha256', 'x')],
        ));
        self::assertSame(0600, fileperms(glob($this->directory . '/storage/issuance/*.json')[0]) & 0777);
    }

    public function testDateEarlierThanAnAuthorizedVoucherOfTheSeriesIsRejectedLocally(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        $client->issue($this->intent(), 'sale:1');
        $yesterday = (new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')))->modify(
            '-1 day',
        );
        $backdated = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(10000, VatRate::STANDARD_21)],
            $yesterday,
        );
        $gateway->calls = [];
        try {
            $client->issue($backdated, 'sale:2');
            self::fail('Expected a date regression failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Nothing was sent', $exception->getMessage());
            self::assertSame([], $gateway->calls);
        }
        // Another series (class B, another sales point) is independent.
        $otherSeries = new Invoice(
            3,
            Receiver::finalConsumerWithDni('30111222'),
            [Item::amount(12100, VatRate::STANDARD_21)],
            $yesterday,
        );
        self::assertSame(Outcome::AUTHORIZED, $client->issue($otherSeries, 'sale:3')->outcome);
    }

    #[DataProvider('tamperedRecords')]
    public function testInvalidJournalRecordsPreventRecoveryBeforeAnyNetworkCall(string $field, mixed $value): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        $client->issue($this->intent(), 'sale:1');
        $file = glob($this->directory . '/storage/issuance/*.json')[0];
        $saved = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $saved['records'][hash('sha256', 'sale:1')][$field] = $value;
        $saved['checksum'] = hash('sha256', json_encode(
            $saved['records'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
        file_put_contents($file, json_encode(
            $saved,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $gateway->calls = [];
        try {
            $client->recover('sale:1');
            self::fail('Expected corruption failure.');
        } catch (\RuntimeException) {
            self::assertSame([], $gateway->calls);
        }
    }

    public static function tamperedRecords(): array
    {
        return [
            'class disagrees with request' => ['class', 'B'],
            'kind disagrees with request' => ['kind', 'CREDIT_NOTE'],
            'unknown class' => ['class', 'Z'],
            'operation disagrees with key' => ['operation', 'sale:2'],
            'missing request' => ['request', null],
            'empty request' => ['request', []],
            'invalid request header' => ['request', ['FeCAEReq' => ['FeCabReq' => 'invalid']]],
            'invalid class type' => ['class', 1],
            'unknown kind' => ['kind', 'UNKNOWN'],
            'invalid sales point type' => ['salesPoint', '2'],
            'invalid sales point range' => ['salesPoint', 0],
            'sales point disagrees with request' => ['salesPoint', 3],
            'number disagrees with request' => ['number', 51],
            'series disagrees with request' => ['series', '2:6'],
            'date disagrees with request' => ['date', '2000-01-01'],
            'invalid calendar date' => ['date', '2026-02-30'],
            'total disagrees with request' => ['total', 1],
            'invalid attempts' => ['attempts', -1],
            'invalid history' => ['history', 'invalid'],
            'invalid issuer' => ['issuerCuit', 'invalid'],
            'unknown environment' => ['environment', 'invalid'],
            'invalid fingerprint' => ['fingerprint', 'invalid'],
            'unknown status' => ['status', 'invalid'],
            'invalid authorization' => ['cae', ''],
            'invalid expiration date' => ['expires', '2026-02-30'],
        ];
    }

    private function pending(): array
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $client = $this->client($gateway);
        $client->issue($this->intent(), 'sale:1');
        return [$client, $gateway, $gateway->calls[1][1]];
    }

    private static function notFound(): WsException
    {
        $errors = [(object) ['Code' => 602, 'Msg' => 'Sin resultados']];
        return new WsException(
            'Sin resultados',
            602,
            errors: $errors,
            response: (object) ['Errors' => (object) ['Err' => $errors]],
        );
    }
}
