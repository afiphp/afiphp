<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\Currency;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Credentials;
use Afiphp\Environment;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\LockTimeout;
use Afiphp\Exceptions\UnconfirmedIssue;
use Afiphp\Exceptions\WsException;
use Afiphp\SalesPoint;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFiscalGateway;
use Tests\Support\FakeTransport;
use Tests\Support\MemoryLogger;

final class QueriesTest extends TestCase
{
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new MemoryLogger();
    }

    private function client(
        FakeFiscalGateway $gateway,
        VatCondition $condition = VatCondition::RESPONSABLE_INSCRIPTO,
        Environment $environment = Environment::HOMOLOGATION,
        ?Credentials $credentials = null,
    ): Arca {
        return new Arca(
            new Configuration(
                '20111111112',
                $condition,
                $credentials ?? $this->identity(),
                $this->storage(),
                $environment,
                logger: $this->logger,
            ),
            $gateway,
        );
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    /** A gateway whose lookups answer what it authorized, as ARCA would. */
    private static function recordingGateway(): FakeFiscalGateway
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onLookup = static function (int $type, int $salesPoint, int $number) use ($gateway): object {
            foreach ($gateway->calls as $call) {
                if ($call[0] !== 'authorize') {
                    continue;
                }
                $request = $call[1];
                $detail = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
                if (
                    $request['FeCAEReq']['FeCabReq']['CbteTipo'] === $type
                    && $request['FeCAEReq']['FeCabReq']['PtoVta'] === $salesPoint
                    && $detail['CbteDesde'] === $number
                ) {
                    return FakeFiscalGateway::found($request);
                }
            }

            throw self::notFound();
        };

        return $gateway;
    }

    private static function notFound(): WsException
    {
        $error = (object) ['Code' => 602, 'Msg' => 'Sin Resultados'];

        return new WsException(
            'ARCA Error: (602) Sin Resultados',
            602,
            errors: [$error],
            response: (object) [
                'Errors' => (object) ['Err' => [$error]],
            ],
        );
    }

    public function testIssuanceHoldsTheSalesPointLockUntilArcaAnswers(): void
    {
        $gateway = new FakeFiscalGateway();
        $storage = $this->storage();
        $gateway->onAuthorize = function (array $request) use ($gateway, $storage): object {
            try {
                $storage->withSalesPointLock('homologation-20111111112-2', 1, static fn () => null);
                self::fail('Another worker must wait for this issuance.');
            } catch (LockTimeout) {
            }
            self::assertNull($storage->withSalesPointLock('homologation-20111111112-3', 1, static fn () => null));
            $gateway->last = 50;

            return FakeFiscalGateway::approved($request);
        };
        $result = $this->client($gateway)->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('12345678'),
                [Item::amount(12100, VatRate::STANDARD_21)],
                self::today(),
            ),
        );
        self::assertSame(50, $result->number);
        self::assertSame('released', $storage->withSalesPointLock(
            'homologation-20111111112-2',
            1,
            static fn () => 'released',
        ));
    }

    public function testIssuanceLogsTheOutcomeWithoutPersonalData(): void
    {
        $gateway = new FakeFiscalGateway();
        $invoice = new Invoice(
            2,
            Receiver::finalConsumerWithDni('12345678'),
            [Item::amount(12100, VatRate::STANDARD_21)],
            self::today(),
        );
        $this->client($gateway)->issue($invoice);
        self::assertSame(['ARCA authorized voucher 50 with CAE 71372966559657'], $this->logger->messages('info'));

        $gateway->onAuthorize = static fn () => throw new \Afiphp\Exceptions\ArcaUnavailable('timeout', true);
        try {
            $this->client($gateway)->issue($invoice);
            self::fail('Expected an unconfirmed issue.');
        } catch (UnconfirmedIssue) {
            self::assertSame(
                ['ARCA did not confirm voucher 51; keep it as previousNumber'],
                $this->logger->messages('error'),
            );
        }
        self::assertStringNotContainsString(
            '12345678',
            serialize(array_map(static fn (array $record): array => [
                $record['message'],
                array_filter($record['context'], 'is_scalar'),
            ], $this->logger->records)),
        );
    }

    public function testIssuanceLogsWhyNothingWasIssued(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::finalConsumerWithDni('12345678'),
            [Item::amount(12100, VatRate::STANDARD_21)],
            self::today(),
        );
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn () => throw new ConfigurationError('Certificate revoked');
        try {
            $this->client($gateway)->issue($invoice);
            self::fail('Expected a configuration error.');
        } catch (ConfigurationError) {
        }
        $gateway->onAuthorize = static fn () => throw new ArcaUnavailable('Could not connect to host', false);
        try {
            $this->client($gateway)->issue($invoice);
            self::fail('Expected ARCA to be unavailable.');
        } catch (ArcaUnavailable) {
        }
        self::assertSame(
            ['ARCA refused the issuer configuration: Certificate revoked'],
            $this->logger->messages('error'),
        );
        self::assertSame(['The voucher was not issued: Could not connect to host'], $this->logger->messages('warning'));
    }

    /** A login that waits for another worker never reached ARCA: nothing to look up. */
    public function testALoginLockTimeoutDuringIssuanceSendsNothing(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static fn () => throw new LockTimeout('Another worker held the lock.');
        try {
            $this->client($gateway)->issue(
                new Invoice(
                    2,
                    Receiver::finalConsumerWithDni('12345678'),
                    [Item::amount(12100, VatRate::STANDARD_21)],
                    self::today(),
                ),
            );
            self::fail('Expected a lock timeout.');
        } catch (LockTimeout) {
        }
        self::assertSame(['last', 'authorize'], array_column($gateway->calls, 0));
        self::assertSame(
            ['The voucher was not issued: Another worker held the lock.'],
            $this->logger->messages('warning'),
        );
    }

    public function testSoapCallsAreLoggedAtDebugWithoutTheirContent(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) ['FEDummyResult' => (object) [
            'AppServer' => 'OK',
            'DbServer' => 'OK',
            'AuthServer' => 'OK',
        ]]);
        $configuration = new Configuration(
            '20111111112',
            VatCondition::RESPONSABLE_INSCRIPTO,
            $this->identity(),
            $this->storage(),
            logger: $this->logger,
        );
        (new \Afiphp\Internal\WebServices\WsFeV1($configuration, $transport))->status();
        self::assertMatchesRegularExpression(
            '/^ARCA call wsfe\.FEDummy: answered in \d+ ms$/',
            $this->logger->messages('debug')[0],
        );
    }

    public function testCheckRefusesAnExpiredCertificateWithoutContactingArca(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'expired'], $key, ['digest_alg' => 'sha256']);
        // Zero days: it expires the moment it is signed.
        $certificate = openssl_csr_sign($csr, null, $key, 0, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($certificate, $certificatePem);
        $credentials = new Credentials($certificatePem, $privatePem);
        self::assertFalse($credentials->isValidAt());

        $gateway = new FakeFiscalGateway();
        try {
            $this->client($gateway, credentials: $credentials)->check();
            self::fail('Expected an expired certificate.');
        } catch (ConfigurationError $exception) {
            self::assertStringContainsString('expired', $exception->getMessage());
            self::assertSame([], $gateway->calls);
        }
    }

    public function testCheckWarnsWhenTheCertificateExpiresSoon(): void
    {
        $credentials = $this->identity();
        self::assertTrue($credentials->isValidAt());
        self::assertLessThanOrEqual(86400, $credentials->expiresAt->getTimestamp() - time());
        $this->client(new FakeFiscalGateway(), credentials: $credentials)->check();
        self::assertStringStartsWith('The ARCA certificate expires in 0 days', $this->logger->messages('warning')[0]);
    }

    public function testCheckVerifiesTheSalesPoint(): void
    {
        $listed = static fn (SalesPoint ...$points): FakeFiscalGateway => tap(
            new FakeFiscalGateway(),
            static function (FakeFiscalGateway $gateway) use ($points): void {
                $gateway->onSalesPoints = static fn (): array => $points;
            },
        );
        $active = new SalesPoint(2, 'CAE', false, null);
        $this->client($listed($active))->check(2);
        $this->client($listed())->check(2); // Homologation lists none and accepts any.

        foreach ([
            'blocked' => [$listed(new SalesPoint(2, 'CAE', true, null)), Environment::HOMOLOGATION],
            'deregistered' => [
                $listed(new SalesPoint(2, 'CAE', false, new DateTimeImmutable('2025-01-01'))),
                Environment::HOMOLOGATION,
            ],
            'does not list' => [$listed($active), Environment::HOMOLOGATION, 3],
            'does not list sales point 2' => [$listed(), Environment::PRODUCTION],
        ] as $expected => $case) {
            [$gateway, $environment] = $case;
            try {
                $this->client($gateway, environment: $environment)->check($case[2] ?? 2);
                self::fail("Expected a sales point that {$expected}.");
            } catch (ConfigurationError $exception) {
                self::assertStringContainsString($expected, $exception->getMessage());
            }
        }
    }

    public function testSalesPointsAreReadStrictly(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) ['FEParamGetPtosVentaResult' => (object) [
            'ResultGet' => (object) ['PtoVenta' => [
                (object) ['Nro' => 2, 'EmisionTipo' => 'CAE', 'Bloqueado' => 'N', 'FchBaja' => 'NULL'],
                (object) ['Nro' => 5, 'EmisionTipo' => 'CAEA', 'Bloqueado' => 'S', 'FchBaja' => '20250131'],
            ]],
        ]]);
        $points = $this->wsfe($transport)->salesPoints();
        self::assertEquals(new SalesPoint(2, 'CAE', false, null), $points[0]);
        self::assertTrue($points[0]->isActive());
        self::assertSame('2025-01-31', $points[1]->deregisteredOn?->format('Y-m-d'));
        self::assertFalse($points[1]->isActive());

        $invalid = new FakeTransport(static fn (): object => (object) ['FEParamGetPtosVentaResult' => (object) [
            'ResultGet' => (object) ['PtoVenta' => [(object) ['Nro' => 2, 'EmisionTipo' => 'CAE', 'Bloqueado' => '?']]],
        ]]);
        $this->expectException(InvalidResponseException::class);
        $this->wsfe($invalid)->salesPoints();
    }

    public function testLastNumberMapsTheSeries(): void
    {
        $gateway = new FakeFiscalGateway();
        self::assertSame(49, $this->client($gateway)->lastNumber(3, InvoiceClass::B, InvoiceKind::CREDIT_NOTE));
        self::assertSame([['last', 8, 3]], $gateway->calls);
    }

    public function testVoucherReadsWhatWasIssued(): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway);
        $issued = $client->issue(new Invoice(
            2,
            Receiver::finalConsumerWithDni('12345678'),
            [Item::amount(12100, VatRate::STANDARD_21), Item::netAmount(1000, VatRate::REDUCED_10_5)],
            self::today(),
            currency: Currency::dollars('1455.5', paidInDollars: true),
            otherTaxes: [new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'IIBB', 10000, '2.5')],
        ));
        self::assertEquals($issued, $client->voucher(InvoiceClass::B, 2, 50));
        self::assertNull($client->voucher(InvoiceClass::B, 2, 51));
        self::assertSame(['lookup', 6, 2, 51], $gateway->calls[array_key_last($gateway->calls)]);
    }

    public function testVoucherReportsAnUnidentifiedConsumerWithoutReceiver(): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway);
        $client->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('12345678'),
                [Item::amount(12100, VatRate::STANDARD_21)],
                self::today(),
            ),
        );
        $original = $gateway->onLookup;
        $gateway->onLookup = static function (int ...$coordinates) use ($original): object {
            $found = $original(...$coordinates);
            $found->DocTipo = 99;
            $found->DocNro = 0;

            return $found;
        };
        $voucher = $client->voucher(InvoiceClass::B, 2, 50);
        self::assertNull($voucher?->receiver);
        self::assertSame(12100, $voucher?->totals->total);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('document type 99');
        $client->fullCreditNote($voucher->invoiceReference(), self::today());
    }

    /** @param \Closure(object): void $tamper */
    #[DataProvider('inconsistentVatRows')]
    public function testVoucherRefusesVatRowsThatDoNotMatchItsAmounts(\Closure $tamper): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway);
        $client->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('12345678'),
                [Item::amount(12100, VatRate::STANDARD_21), Item::netAmount(1000, VatRate::REDUCED_10_5)],
                self::today(),
            ),
        );
        $original = $gateway->onLookup;
        $gateway->onLookup = static function (int ...$coordinates) use ($original, $tamper): object {
            $found = $original(...$coordinates);
            $tamper($found);

            return $found;
        };
        $this->expectException(InvalidResponseException::class);
        $client->voucher(InvoiceClass::B, 2, 50);
    }

    public static function inconsistentVatRows(): array
    {
        return [
            'repeated rate' => [static function (object $found): void {
                $found->Iva->AlicIva[1]->Id = $found->Iva->AlicIva[0]->Id;
            }],
            'missing row' => [static function (object $found): void {
                array_pop($found->Iva->AlicIva);
            }],
            'no rows with VAT' => [static function (object $found): void {
                $found->Iva = null;
            }],
        ];
    }

    public function testVoucherRefusesAnotherVoucherAndUnsupportedCurrencies(): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway);
        $client->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('12345678'),
                [Item::amount(12100, VatRate::STANDARD_21)],
                self::today(),
            ),
        );
        $original = $gateway->onLookup;
        $gateway->onLookup = static function (int ...$coordinates) use ($original): object {
            $found = $original(...$coordinates);
            $found->CbteDesde = $found->CbteHasta = 49;

            return $found;
        };
        try {
            $client->voucher(InvoiceClass::B, 2, 50);
            self::fail('Expected another voucher to be refused.');
        } catch (InvalidResponseException) {
        }
        $gateway->onLookup = static function (int ...$coordinates) use ($original): object {
            $found = $original(...$coordinates);
            $found->MonId = '060';

            return $found;
        };
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('060');
        $client->voucher(InvoiceClass::B, 2, 50);
    }

    public function testFullCreditNoteMirrorsTheOriginalExactly(): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway);
        $date = self::today();
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [
                Item::netAmount(10001, VatRate::STANDARD_21),
                Item::amount(1105, VatRate::REDUCED_10_5),
                Item::amount(999, VatRate::REDUCED_10_5),
                Item::netAmount(500, VatRate::EXEMPT),
                Item::netAmount(300, VatRate::UNTAXED),
            ],
            $date,
            service: new ServicePeriod($date->modify('-30 days'), $date->modify('-1 day'), $date),
            currency: Currency::dollars('1455.5', paidInDollars: false),
            otherTaxes: [new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'Percepción IIBB', 10001, '3.5')],
        );
        $issued = $client->issue($invoice);
        $note = $client->fullCreditNote($issued->invoiceReference(), $date->modify('+1 day'));

        self::assertSame(InvoiceKind::CREDIT_NOTE, $note->kind);
        self::assertEquals($issued->invoiceReference(), $note->original);
        self::assertEquals($invoice->receiver, $note->receiver);
        self::assertEquals($invoice->currency, $note->currency);
        self::assertEquals($invoice->otherTaxes, $note->otherTaxes);
        self::assertSame($date->modify('+1 day')->format('Y-m-d'), $note->service?->paymentDue->format('Y-m-d'));
        self::assertEquals($issued->totals, $client->preview($note));

        $credited = $client->issue($note);
        self::assertSame(InvoiceClass::A, $credited->class);
        self::assertSame(InvoiceKind::CREDIT_NOTE, $credited->kind);
    }

    public function testFullCreditNoteOfAClassCInvoice(): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway, VatCondition::MONOTRIBUTISTA);
        $issued = $client->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('12345678'),
                [Item::amount(1500), Item::amount(2500)],
                self::today(),
            ),
        );
        $note = $client->fullCreditNote($issued->invoiceReference(), self::today(), salesPoint: 3);
        self::assertSame(3, $note->salesPoint);
        self::assertEquals([Item::amount(4000)], $note->items);
        self::assertEquals($issued->totals, $client->preview($note));
    }

    public function testFullCreditNoteRefusesWhatItCannotMirror(): void
    {
        $gateway = self::recordingGateway();
        $client = $this->client($gateway);
        $issued = $client->issue(
            new Invoice(
                2,
                Receiver::finalConsumerWithDni('12345678'),
                [Item::amount(12100, VatRate::STANDARD_21)],
                self::today(),
            ),
        );
        $reference = $issued->invoiceReference();
        $original = $gateway->onLookup;
        $cases = [
            'not its base times the rate' => static function (object $found): void {
                $found->Iva->AlicIva[0]->Importe = '25.00';
                $found->ImpIVA = '25.00';
                $found->ImpTotal = '125.00';
            },
            'Opcionales' => static function (object $found): void {
                $found->Opcionales = (object) ['Opcional' => [(object) ['Id' => '27', 'Valor' => 'SCA']]];
            },
            'dated' => static function (object $found): void {
                $found->CbteFch = '20200101';
                $found->FchVto = '20200111';
            },
        ];
        foreach ($cases as $message => $change) {
            $gateway->onLookup = static function (int ...$coordinates) use ($original, $change): object {
                $found = $original(...$coordinates);
                $change($found);

                return $found;
            };
            try {
                $client->fullCreditNote($reference, self::today());
                self::fail("Expected a refusal: {$message}.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        $gateway->onLookup = $original;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ARCA has no voucher 2-51');
        $client->fullCreditNote(new InvoiceReference(InvoiceClass::B, 2, 51, self::today()), self::today());
    }
}

/**
 * @template T
 * @param T $value
 * @param callable(T): void $callback
 * @return T
 */
function tap(mixed $value, callable $callback): mixed
{
    $callback($value);

    return $value;
}
