<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\SoapException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\WebServices\Failures;
use Afiphp\Internal\WebServices\WsFeV1;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeTicketProvider;
use Tests\Support\FakeTransport;

/** Each row of the classification: ARCA unavailable (wait), configuration (call an administrator) or neither. */
final class FailuresTest extends TestCase
{
    /** @param class-string $expected */
    #[DataProvider('faults')]
    public function testSoapFaults(string $code, string $text, string $warnings, string $expected, ?bool $sent): void
    {
        $failure = Failures::fromFault(new \SoapFault($code, $text), $warnings);
        self::assertInstanceOf($expected, $failure);
        self::assertInstanceOf(\SoapFault::class, $failure->getPrevious());
        if ($failure instanceof ArcaUnavailable) {
            self::assertSame($sent, $failure->sent);
        }
    }

    public static function faults(): array
    {
        $unavailable = ArcaUnavailable::class;
        $configuration = ConfigurationError::class;

        return [
            'refused connection' => ['HTTP', 'Could not connect to host', '', $unavailable, false],
            'DNS' => [
                'HTTP',
                'Could not connect to host',
                'php_network_getaddresses: getaddrinfo failed',
                $unavailable,
                false,
            ],
            'read timeout' => ['HTTP', 'Error Fetching http headers', '', $unavailable, true],
            'gateway error' => ['HTTP', 'Bad Gateway', '', $unavailable, true],
            'server error' => ['HTTP', 'Internal Server Error', '', $unavailable, true],
            'TLS' => [
                'HTTP',
                'Could not connect to host',
                'SoapClient::__doRequest(): SSL operation failed with code 1',
                $configuration,
                null,
            ],
            'untrusted certificate' => [
                'HTTP',
                'Could not connect to host',
                'certificate verify failed',
                $configuration,
                null,
            ],
            'forbidden' => ['HTTP', 'Forbidden', '', $configuration, null],
            'not found' => ['HTTP', 'Not Found', '', $configuration, null],
            'expired certificate' => ['ns1:cms.cert.expired', 'Certificado expirado', '', $configuration, null],
            'untrusted signer' => [
                'ns1:cms.cert.untrusted',
                'Certificado no emitido por AC de confianza',
                '',
                $configuration,
                null,
            ],
            'bad signature' => ['ns1:cms.sign.invalid', 'Firma inválida', '', $configuration, null],
            'service not enabled' => ['ns1:coe.notAuthorized', 'Computador no autorizado', '', $configuration, null],
            'ticket elsewhere' => [
                'ns1:coe.alreadyAuthenticated',
                'El CEE ya posee un TA valido',
                '',
                $configuration,
                null,
            ],
            'skewed clock' => ['ns1:xml.generationTime.invalid', 'generationTime invalido', '', $configuration, null],
            'server fault' => ['soapenv:Server', 'Internal error', '', $unavailable, true],
            'SOAP 1.2 server fault' => ['Receiver', 'Internal error', '', $unavailable, true],
            'client fault' => ['soap:Client', 'Malformed request', '', SoapException::class, null],
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('wsfeErrors')]
    public function testWsfeErrors(int $code, string $expected): void
    {
        $transport = new FakeTransport(static fn (): object => (object) [
            'FECompConsultarResult' => (object) ['Errors' => (object) ['Err' => [(object) [
                'Code' => $code,
                'Msg' => 'Error',
            ]]]],
        ]);
        try {
            (new WsFeV1($this->configuration(), $transport, new FakeTicketProvider()))->lookup(1, 2, 50);
            self::fail('Expected an ARCA error.');
        } catch (\Exception $exception) {
            self::assertSame($expected, $exception::class);
            $error = $exception instanceof WsException ? $exception : $exception->getPrevious();
            self::assertInstanceOf(WsException::class, $error);
            self::assertSame($code, $error->getCode());
        }
    }

    public static function wsfeErrors(): array
    {
        return [
            'internal' => [500, ArcaUnavailable::class],
            'database' => [501, ArcaUnavailable::class],
            'unexpected' => [502, ArcaUnavailable::class],
            'invalid token' => [600, ConfigurationError::class],
            'CUIT not authorized' => [601, ConfigurationError::class],
            'not found' => [602, WsException::class],
            'validation' => [10016, WsException::class],
        ];
    }

    public function testAFailedLoginMeansTheRequestWasNeverSent(): void
    {
        $tickets = new FakeTicketProvider();
        $tickets->failure = new ArcaUnavailable('WSAA did not return a login ticket.', true);
        $transport = new FakeTransport();
        try {
            (new WsFeV1($this->configuration(), $transport, $tickets))->lookup(1, 2, 50);
            self::fail('Expected ARCA to be unavailable.');
        } catch (ArcaUnavailable $exception) {
            self::assertFalse($exception->sent);
            self::assertSame([], $transport->calls);
        }
    }
}
