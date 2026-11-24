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
use Afiphp\Billing\QrCode;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Environment;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\InvoiceRejected;
use Afiphp\Exceptions\UnconfirmedIssue;
use Afiphp\Exceptions\WsException;
use Afiphp\ServiceStatus;
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

    private function intent(int $net = 15000, ?DateTimeImmutable $date = null): Invoice
    {
        return new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount($net, VatRate::STANDARD_21)],
            $date ?? self::today(),
        );
    }

    public function testPreviewHasNoSideEffectsAndIssueSendsTheNextNumberOnce(): void
    {
        $gateway = new FakeFiscalGateway();
        $client = $this->client($gateway);
        self::assertSame(18150, $client->preview($this->intent())->total);
        self::assertSame([], $gateway->calls);
        $result = $client->issue($this->intent());
        self::assertSame(50, $result->number);
        self::assertSame('71372966559657', $result->authorization->cae);
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
        self::assertSame('181.50', $gateway->calls[1][1]['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0]['ImpTotal']);
        self::assertSame(50, $result->invoiceReference()->number);
    }

    public function testResultCarriesTheSameTotalsAsThePreview(): void
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
            self::today(),
            otherTaxes: [new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'IIBB', 10000, '2.5')],
        );
        $client = $this->client(new FakeFiscalGateway());
        $preview = $client->preview($invoice);
        self::assertEquals($preview, $client->issue($invoice)->totals);
        self::assertCount(2, $preview->vatByRate);
    }

    public function testAuthorizedResultCarriesTheRg4892QrPayload(): void
    {
        $receiver = Receiver::finalConsumerWithDni('30111222');
        $currency = Currency::dollars('1455.50', paidInDollars: false);
        $result = $this->client(new FakeFiscalGateway())->issue(
            new Invoice(
                2,
                $receiver,
                [Item::netAmount(15000, VatRate::STANDARD_21)],
                self::today(),
                currency: $currency,
            ),
        );
        self::assertSame(
            '{"ver":1,"fecha":"'
            . $result->date->format('Y-m-d')
            . '","cuit":20111111112,"ptoVta":2,'
            . '"tipoCmp":6,"nroCmp":50,"importe":181.50,"moneda":"DOL","ctz":1455.5,'
            . '"tipoDocRec":96,"nroDocRec":30111222,"tipoCodAut":"E","codAut":71372966559657}',
            self::qrJson($result->qr),
        );
        $voucher = $result->invoiceReference();
        $cae = $result->authorization->cae;
        self::assertSame(
            $result->qr,
            QrCode::url('20111111112', $voucher, $result->totals->total, $cae, $receiver, $currency),
        );
        self::assertSame(
            '{"ver":1,"fecha":"'
            . $voucher->date->format('Y-m-d')
            . '","cuit":20111111112,"ptoVta":2,'
            . '"tipoCmp":6,"nroCmp":50,"importe":181.50,"moneda":"PES","ctz":1,'
            . '"tipoCodAut":"E","codAut":71372966559657}',
            self::qrJson(QrCode::url('20111111112', $voucher, 18150, $cae)),
        );
    }

    public function testRejectionKeepsObservationsAndIsNotLookedUp(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = self::rejecting(100, 'Rechazado');
        try {
            $this->client($gateway)->issue($this->intent());
            self::fail('Expected a rejection.');
        } catch (InvoiceRejected $exception) {
            self::assertSame([50, ['(100) Rechazado']], [$exception->number, $exception->observations]);
        }
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
    }

    public function testALostAnswerIsLookedUpOnce(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $gateway->onLookup = static fn (): object => FakeFiscalGateway::found($gateway->calls[1][1]);
        $result = $this->client($gateway)->issue($this->intent());
        self::assertSame(50, $result->number);
        self::assertSame(['last', 'authorize', 'lookup'], array_column($gateway->calls, 0));
        self::assertSame(50, $gateway->calls[2][3]);
    }

    public function testAnUnconfirmedAttemptGivesItsNumberToTheApplication(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $gateway->onLookup = static fn (): object => throw new \RuntimeException('Still down');
        try {
            $this->client($gateway)->issue($this->intent());
            self::fail('Expected an unconfirmed issue.');
        } catch (UnconfirmedIssue $exception) {
            self::assertSame(50, $exception->number);
            self::assertSame('Timeout', $exception->getPrevious()?->getMessage());
        }
    }

    /** A request that never reached ARCA, or that ARCA refused for the setup, has nothing to look up. */
    #[DataProvider('unsentFailures')]
    public function testAFailureBeforeArcaGotTheRequestIsNotLookedUp(\Throwable $failure): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw $failure;
        try {
            $this->client($gateway)->issue($this->intent());
            self::fail('Expected the failure.');
        } catch (\Throwable $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
    }

    public static function unsentFailures(): array
    {
        return [
            'no connection' => [new ArcaUnavailable('Could not connect to host', false)],
            'configuration' => [new ConfigurationError('Certificate expired')],
        ];
    }

    public function testAnUnreachableArcaKeepsAnEarlierAttemptWithoutSending(): void
    {
        $gateway = new FakeFiscalGateway();
        $failure = new ArcaUnavailable('Timeout', true);
        $gateway->onLookup = static fn (): object => throw $failure;
        try {
            $this->client($gateway)->issue($this->intent(), previousNumber: 50);
            self::fail('Expected ARCA to be unavailable.');
        } catch (ArcaUnavailable $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
    }

    #[DataProvider('contradictoryAuthorizations')]
    public function testContradictoryEvidenceIsNeverTakenAsAnAnswer(string $case): void
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
        self::assertSame(50, self::unconfirmed(fn () => $this->client($gateway)->issue($this->intent())));
        self::assertSame(['last', 'authorize', 'lookup'], array_column($gateway->calls, 0));
    }

    public static function contradictoryAuthorizations(): array
    {
        return array_map(static fn (string $case): array => [$case], [
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

    /**
     * After a lost answer, a voucher that differs is someone else's: the next attempt issues as new.
     * One that cannot be compared stays unconfirmed, and the next attempt sends nothing.
     */
    #[DataProvider('lookups')]
    public function testTheLookupNeverConfirmsOnTheNumberAlone(string $case, bool $other): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $gateway->onLookup = static function () use ($gateway, $case): object {
            $found = FakeFiscalGateway::found($gateway->calls[1][1]);
            match ($case) {
                'amount' => $found->ImpTotal = '181.51',
                'receiver' => $found->DocNro = '20999999999',
                'vat' => $found->Iva->AlicIva[0]->Importe = '31.51',
                'range' => $found->CbteHasta = 51,
                'missing-condition' => $found->CondicionIVAReceptorId = null,
                'missing-vat' => $found->Iva = null,
                'cae' => $found->CodAutorizacion = 'invalid',
                'decimal' => $found->ImpNeto = '150.001',
                'emission' => $found->EmisionTipo = 'CAEA',
                'integer-coercion' => $found->DocTipo = '80oops',
            };
            return $found;
        };
        $client = $this->client($gateway);
        $number = self::unconfirmed(fn () => $client->issue($this->intent()));
        $gateway->onAuthorize = null;
        $gateway->last = 50;
        $first = $gateway->calls[1];
        $gateway->calls = [1 => $first];
        if (!$other) {
            self::assertSame(50, self::unconfirmed(fn () => $client->issue($this->intent(), $number)));
            self::assertSame(['authorize', 'lookup'], array_column($gateway->calls, 0));
            return;
        }
        $result = $client->issue($this->intent(), $number);
        self::assertSame(51, $result->number);
        self::assertSame(['authorize', 'lookup', 'last', 'authorize'], array_column($gateway->calls, 0));
    }

    public static function lookups(): array
    {
        return [
            ['amount',            true],
            ['receiver',          true],
            ['vat',               true],
            ['range',             true],
            ['missing-condition', false],
            ['missing-vat',       false],
            ['cae',               false],
            ['decimal',           false],
            ['emission',          false],
            ['integer-coercion',  false],
        ];
    }

    public function testAnEarlierAttemptFoundOnAnotherDayIsTheResult(): void
    {
        $gateway = new FakeFiscalGateway();
        $monday = self::today()->modify('-6 days');
        $earlier = $this->request($this->intent(date: $monday), 50);
        $gateway->onLookup = static fn (): object => FakeFiscalGateway::found($earlier);
        $result = $this->client($gateway)->issue($this->intent(), previousNumber: 50);
        self::assertSame([50, $monday->format('Y-m-d')], [$result->number, $result->date->format('Y-m-d')]);
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
    }

    public function testAnEarlierAttemptThatNeverLandedIsSentAgainWithItsNumberAndTodaysDate(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onLookup = static fn (): object => throw self::notFound();
        $result = $this->client($gateway)->issue($this->intent(), previousNumber: 50);
        self::assertSame(50, $result->number);
        self::assertSame(['lookup', 'authorize'], array_column($gateway->calls, 0));
        $sent = $gateway->calls[1][1]['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        self::assertSame([50, self::today()->format('Ymd')], [$sent['CbteDesde'], $sent['CbteFch']]);
    }

    /** ARCA refuses the resend when the earlier attempt took the number after all. */
    public function testARefusedResendLooksTheNumberUpAgain(): void
    {
        $earlier = $this->request($this->intent(date: self::today()->modify('-1 day')), 50);
        $gateway = new FakeFiscalGateway();
        $lookups = 0;
        $gateway->onLookup = static function () use (&$lookups, $earlier): object {
            if (++$lookups === 1) {
                throw self::notFound();
            }
            return FakeFiscalGateway::found($earlier);
        };
        $gateway->onAuthorize = self::rejecting(10016, 'Numero ya utilizado');
        $result = $this->client($gateway)->issue($this->intent(), previousNumber: 50);
        self::assertSame(50, $result->number);
        self::assertSame(['lookup', 'authorize', 'lookup'], array_column($gateway->calls, 0));

        $gateway->onLookup = static fn (): object => throw self::notFound();
        $gateway->onAuthorize = self::rejecting(10015, 'Receptor invalido');
        $this->expectExceptionObject(new InvoiceRejected(50, ['(10015) Receptor invalido']));
        $this->client($gateway)->issue($this->intent(), previousNumber: 50);
    }

    public function testAnEarlierAttemptThatCannotBeLookedUpSendsNothing(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onLookup = static fn (): object => throw new \RuntimeException('Unavailable');
        self::assertSame(50, self::unconfirmed(fn () => $this->client($gateway)->issue($this->intent(), 50)));
        self::assertSame(['lookup'], array_column($gateway->calls, 0));
    }

    #[DataProvider('periodLookup')]
    public function testAnAssociatedPeriodTakesPartInTheLookup(?array $period, bool $authorized): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $gateway->onLookup = static function () use ($gateway, $period): object {
            $found = FakeFiscalGateway::found($gateway->calls[1][1]);
            if ($period !== []) {
                $found->PeriodoAsoc = $period === null ? null : (object) $period;
            }
            return $found;
        };
        $today = self::today();
        $note = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(1000, VatRate::STANDARD_21)],
            $today,
            kind: InvoiceKind::CREDIT_NOTE,
            period: new AssociatedPeriod($today->modify('first day of last month'), $today->modify('-1 day')),
        );
        if (!$authorized) {
            $this->expectException(UnconfirmedIssue::class);
        }
        self::assertSame(50, $this->client($gateway)->issue($note)->number);
    }

    public static function periodLookup(): array
    {
        return [
            'same period' => [[], true],
            'other period' => [['FchDesde' => '20000101', 'FchHasta' => '20000131'], false],
            'period omitted' => [null, false],
            'malformed period' => [['FchDesde' => '2000-01-01', 'FchHasta' => '20000131'], false],
        ];
    }

    /** An empty period is "none" for an invoice; a real one means a different voucher. */
    #[DataProvider('periodOnInvoice')]
    public function testAnUnexpectedAssociatedPeriodIsNotTheSameInvoice(array $period, bool $authorized): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn (): object => throw new \RuntimeException('Timeout');
        $gateway->onLookup = static function () use ($gateway, $period): object {
            $found = FakeFiscalGateway::found($gateway->calls[1][1]);
            $found->PeriodoAsoc = (object) $period;
            return $found;
        };
        if (!$authorized) {
            $this->expectException(UnconfirmedIssue::class);
        }
        self::assertSame(50, $this->client($gateway)->issue($this->intent())->number);
    }

    public static function periodOnInvoice(): array
    {
        return [
            'empty' => [['FchDesde' => '', 'FchHasta' => ''], true],
            'real' => [['FchDesde' => '20260101', 'FchHasta' => '20260131'], false],
        ];
    }

    public function testCheckPassesWhenArcaWorksAndTheIssuerIsAuthorized(): void
    {
        $gateway = new FakeFiscalGateway();
        $this->client($gateway)->check();
        self::assertSame(['status', 'salesPoints'], array_column($gateway->calls, 0));
    }

    public function testCheckReportsArcaUnavailableBeforeLoggingIn(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->status = new ServiceStatus('OK', 'No disponible', 'OK');
        try {
            $this->client($gateway)->check();
            self::fail('Expected ARCA to be unavailable.');
        } catch (ArcaUnavailable) {
            self::assertSame(['status'], array_column($gateway->calls, 0));
        }
    }

    public function testCheckReportsTheIssuersConfiguration(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onSalesPoints = static fn () => throw new ConfigurationError('Certificate expired');
        $this->expectException(ConfigurationError::class);
        $this->client($gateway)->check();
    }

    public function testThePreviousNumberMustBeAVoucherNumber(): void
    {
        $gateway = new FakeFiscalGateway();
        try {
            $this->client($gateway)->issue($this->intent(), previousNumber: 0);
            self::fail('Expected an invalid previous number.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $gateway->calls);
        }
    }

    /** The request ARCA would have received for $invoice with $number. */
    private function request(Invoice $invoice, int $number): array
    {
        $gateway = new FakeFiscalGateway();
        $gateway->last = $number - 1;
        $this->client($gateway)->issue($invoice);

        return $gateway->calls[1][1];
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    private static function rejecting(int $code, string $message): \Closure
    {
        return static function (array $request) use ($code, $message): object {
            $response = FakeFiscalGateway::approved($request);
            $response->FeCabResp->Resultado = 'R';
            $detail = $response->FeDetResp->FECAEDetResponse[0];
            $detail->Resultado = 'R';
            $detail->CAE = '';
            $detail->CAEFchVto = '';
            $detail->Observaciones = (object) ['Obs' => (object) ['Code' => $code, 'Msg' => $message]];
            return $response;
        };
    }

    /** @param callable(): mixed $issue */
    private static function unconfirmed(callable $issue): int
    {
        try {
            $issue();
        } catch (UnconfirmedIssue $exception) {
            return $exception->number;
        }
        self::fail('Expected an unconfirmed issue.');
    }

    private static function qrJson(?string $url): string
    {
        return base64_decode(substr((string) $url, strlen(QrCode::URL . '?p=')), true);
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
