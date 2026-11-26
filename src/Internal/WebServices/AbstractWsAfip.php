<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Configuration;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use InvalidArgumentException;
use SoapFault;
use Throwable;
use UnexpectedValueException;

/** @internal SOAP call and ARCA error mapping. Never retries a call. */
abstract class AbstractWsAfip
{
    protected const WSFE = 'wsfe';
    protected const WSAA = 'wsaa';
    protected const PADRON_A5 = 'padron-a5';
    protected const PADRON_A13 = 'padron-a13';

    private const URLS = [
        'production' => [
            self::WSFE => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
            self::WSAA => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            self::PADRON_A5 => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5',
            self::PADRON_A13 => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13',
        ],
        'homologation' => [
            self::WSFE => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            self::WSAA => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            self::PADRON_A5 => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5',
            self::PADRON_A13 => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13',
        ],
    ];

    protected SoapTransport $transport;

    public function __construct(
        protected Configuration $configuration,
        ?SoapTransport $transport = null,
    ) {
        $this->transport = $transport ?? new NativeSoapTransport(
            __DIR__ . "/Wsdl/{$this->service()}-{$configuration->environment->value}.xml",
            self::URLS[$configuration->environment->value][$this->service()],
            $this->service() !== self::WSFE,
            $configuration->timeout,
            legacyTls: $this->service() === self::WSFE,
        );
    }

    abstract protected function service(): string;

    public function execute(string $method, array $params = []): object
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $method)) {
            throw new InvalidArgumentException('Invalid SOAP method name.');
        }
        // No automatic retries: a lost authorization response may already be a valid invoice.
        $started = hrtime(true);
        $outcome = 'failed';
        try {
            $response = $this->transport->call($method, $params);
            $outcome = 'answered';
        } catch (Throwable $exception) {
            $outcome = $exception::class;
            throw $exception;
        } finally {
            // Only the operation and its timing: requests carry personal data and credentials.
            $this->configuration->logger->debug('ARCA call {service}.{method}: {outcome} in {milliseconds} ms', [
                'service' => $this->service(),
                'method' => $method,
                'outcome' => $outcome,
                'milliseconds' => intdiv(hrtime(true) - $started, 1_000_000),
            ]);
        }
        if ($response instanceof SoapFault) {
            throw Failures::fromFault($response);
        }
        $result = property_exists($response, "{$method}Result") ? $response->{"{$method}Result"} : $response;
        if (!is_object($result)) {
            throw new InvalidResponseException("Invalid ARCA result for {$method}.", $response, $method);
        }
        $errors = WsResponse::items($result->Errors->Err ?? null);
        if ($errors !== []) {
            try {
                $messages = WsResponse::messages($errors);
            } catch (UnexpectedValueException $exception) {
                throw new InvalidResponseException('Malformed ARCA error response.', $result, $method, $exception);
            }
            $error = new WsException(
                'ARCA Error: ' . implode(PHP_EOL, $messages),
                (int) $errors[0]->Code,
                errors: $errors,
                response: $result,
            );

            throw $this->service() === self::WSFE ? Failures::fromWsfeError($error) : $error;
        }
        if (!property_exists($result, 'ResultGet')) {
            return $result;
        }
        if (!is_object($result->ResultGet)) {
            throw new InvalidResponseException('ARCA returned an empty or invalid ResultGet.', $result, $method);
        }

        return $result->ResultGet;
    }
}
