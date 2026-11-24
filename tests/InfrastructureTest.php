<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\WebServices\Ticket;
use Afiphp\Internal\WebServices\WsFeV1;
use Afiphp\NetworkOptions;
use Tests\Support\FakeTicketProvider;
use Tests\Support\FakeTransport;

/** Transport/authentication regression controls survive the public-contract replacement. */
final class InfrastructureTest extends TestCase
{
    public function testConstructionDoesNotContactArcaAndADummyCallDoesNotLogin(): void
    {
        $provider = new FakeTicketProvider();
        $transport = new FakeTransport(static fn (): object => (object) ['FEDummyResult' => (object) [
            'AppServer' => 'OK',
        ]]);
        $service = $this->wsfe($transport, $provider);
        self::assertSame([], $transport->calls);
        self::assertSame('OK', $service->execute('FEDummy')->AppServer);
        self::assertSame(0, $provider->calls);
        self::assertArrayNotHasKey('Auth', $transport->calls[0][1]);
    }

    public function testStatusReadsTheDummyWithoutLoggingIn(): void
    {
        $provider = new FakeTicketProvider();
        $transport = new FakeTransport(static fn (): object => (object) ['FEDummyResult' => (object) [
            'AppServer' => 'OK',
            'DbServer' => 'No disponible',
            'AuthServer' => 'OK',
        ]]);
        $status = $this->wsfe($transport, $provider)->status();
        self::assertSame('No disponible', $status->dbServer);
        self::assertFalse($status->isAvailable());
        self::assertSame(0, $provider->calls);
    }

    public function testIncompleteStatusIsAProtocolFailure(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) ['FEDummyResult' => (object) [
            'AppServer' => 'OK',
        ]]);
        $this->expectException(InvalidResponseException::class);
        $this->wsfe($transport)->status();
    }

    public function testNoSalesPointsIsAnEmptyListButNotAConfigurationError(): void
    {
        $answer = static fn (int $code): FakeTransport => new FakeTransport(static fn (): object => (object) [
            'FEParamGetPtosVentaResult' => (object) ['Errors' => (object) ['Err' => [(object) [
                'Code' => $code,
                'Msg' => 'Error',
            ]]]],
        ]);
        $transport = $answer(602);
        self::assertSame([], $this->wsfe($transport)->salesPoints());
        self::assertSame('FEParamGetPtosVenta', $transport->calls[0][0]);
        self::assertArrayHasKey('Auth', $transport->calls[0][1]);
        $this->expectException(ConfigurationError::class);
        $this->wsfe($answer(601))->salesPoints();
    }

    public function testRefreshesExpiredCredentialsWithoutLosingAStillValidTicketEarly(): void
    {
        $old = new readonly class((object) ['valid' => true]) extends Ticket {
            public function __construct(
                private object $state,
            ) {
                parent::__construct('old', 'sign');
            }

            public function isValid(): bool
            {
                return $this->state->valid;
            }

            public function expire(): void
            {
                $this->state->valid = false;
            }
        };
        self::assertTrue($old->isValid());
        $provider = new FakeTicketProvider([
            $old,
            new Ticket('new', 'sign', new \DateTimeImmutable('+1 hour')),
        ]);
        $transport = new FakeTransport(static fn (): object => (object) [
            'CbteNro' => 49,
            'CbteTipo' => 1,
            'PtoVta' => 2,
        ]);
        $service = $this->wsfe($transport, $provider);
        self::assertSame(49, $service->lastNumber(1, 2));
        $old->expire();
        $service->lastNumber(1, 2);
        self::assertSame(2, $provider->calls);
        self::assertSame('new', $transport->calls[1][1]['Auth']['Token']);
    }

    public function testDollarRateIsAuthenticatedAndNormalized(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) [
            'FEParamGetCotizacionResult' => (object) ['ResultGet' => (object) [
                'MonId' => 'DOL',
                'MonCotiz' => '1455.500000',
                'FchCotiz' => '20261120',
            ]],
        ]);
        $rate = $this->wsfe($transport)->dollarRate(new \DateTimeImmutable('2026-11-20'));
        self::assertSame('1455.5', $rate);
        [$method, $params] = $transport->calls[0];
        self::assertSame('FEParamGetCotizacion', $method);
        self::assertSame('DOL', $params['MonId']);
        self::assertSame('20261120', $params['FchCotiz']);
        self::assertSame('token', $params['Auth']['Token']);
        $this->wsfe($transport)->dollarRate(null);
        self::assertArrayNotHasKey('FchCotiz', $transport->calls[1][1]);
    }

    public function testInvalidDollarRateIsAProtocolFailure(): void
    {
        foreach ([
            ['MonId' => 'DOL', 'MonCotiz' => 1455.5],
            ['MonId' => 'DOL', 'MonCotiz' => '0'],
            ['MonId' => 'DOL', 'MonCotiz' => '12345.5'],
            ['MonId' => 'DOL'],
            ['MonId' => '060', 'MonCotiz' => '1455.5'],
        ] as $result) {
            $transport = new FakeTransport(static fn (): object => (object) [
                'FEParamGetCotizacionResult' => (object) ['ResultGet' => (object) $result],
            ]);
            try {
                $this->wsfe($transport)->dollarRate(null);
                self::fail('Expected an invalid rate.');
            } catch (InvalidResponseException $exception) {
                self::assertSame('FEParamGetCotizacion', $exception->getOperation());
            }
        }
    }

    public function testExpiredProviderCredentialsNeverReachTheTransport(): void
    {
        $transport = new FakeTransport();
        $provider = new FakeTicketProvider([new Ticket('old', 'sign', new \DateTimeImmutable('-1 hour'))]);
        try {
            $this->wsfe($transport, $provider)->lookup(1, 2, 50);
            self::fail('Expected expired credentials.');
        } catch (\RuntimeException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testGlobalErrorsKeepTheOriginalResponseIncludingPossibleApprovalEvidence(): void
    {
        $response = $this->authorizationResponse([50]);
        $response->FECAESolicitarResult->Errors = (object) ['Err' => [(object) [
            'Code' => 100,
            'Msg' => 'Error',
        ]]];
        $transport = new FakeTransport(static fn (): object => $response);
        try {
            $this->wsfe($transport)->execute('FECAESolicitar', []);
            self::fail('Expected global error.');
        } catch (WsException $exception) {
            self::assertSame(100, $exception->getCode());
            self::assertCount(1, $exception->getErrors());
            self::assertSame('A', $exception->getResponse()->FeDetResp->FECAEDetResponse[0]->Resultado);
        }
    }

    public function testReturnedSoapFaultIsTransportUncertaintyNotAFiscalRejection(): void
    {
        $transport = new FakeTransport(static fn (): object => new \SoapFault('HTTP', 'Timeout'));
        try {
            $this->wsfe($transport)->lookup(1, 2, 50);
            self::fail('Expected SOAP failure.');
        } catch (ArcaUnavailable $exception) {
            self::assertTrue($exception->sent);
            self::assertCount(1, $transport->calls);
        }
    }

    public function testNullResultGetCannotBecomeAnEmptyInvoice(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) ['ResultGet' => null]);
        $this->expectException(InvalidResponseException::class);
        $this->wsfe($transport)->lookup(1, 2, 50);
    }

    public function testMissingLastNumberAndWrongSeriesCannotAllocateInvoiceOne(): void
    {
        foreach ([
            (object) [],
            (object) ['CbteNro' => 0, 'CbteTipo' => 6, 'PtoVta' => 2],
            (object) ['CbteNro' => true, 'CbteTipo' => 1, 'PtoVta' => 2],
        ] as $response) {
            try {
                $this->wsfe(new FakeTransport(static fn (): object => $response))->lastNumber(1, 2);
                self::fail('Expected invalid sequence.');
            } catch (InvalidResponseException $exception) {
                self::assertSame($response, $exception->getResponse());
            }
        }
    }

    public function testOptionalDiagnosticsRetainResponseRedactSecretsAndDoNotMaskSuccess(): void
    {
        $transport = new FakeTransport(static fn (): object => (object) ['AppServer' => 'OK']);
        $transport->request = '<Envelope><Token>secret-token</Token><Sign>secret-sign</Sign></Envelope>';
        $transport->response = '<Envelope><Result>actual-response</Result></Envelope>';
        $service = new WsFeV1(
            $this->configuration(network: new NetworkOptions(saveSoapMessages: true)),
            $transport,
            new FakeTicketProvider(),
        );
        self::assertSame('OK', $service->execute('FEDummy')->AppServer);
        $files = glob($this->directory . '/storage/diagnostics/*.xml');
        self::assertCount(2, $files);
        $contents = implode('', array_map('file_get_contents', $files));
        self::assertStringContainsString('actual-response', $contents);
        self::assertStringNotContainsString('secret-token', $contents);
        self::assertStringNotContainsString('secret-sign', $contents);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        // A disk failure in optional diagnostics is not a fiscal failure.
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/storage/diagnostics');
        file_put_contents($this->directory . '/storage/diagnostics', 'not a directory');
        self::assertSame('OK', $service->execute('FEDummy')->AppServer);
    }
}
