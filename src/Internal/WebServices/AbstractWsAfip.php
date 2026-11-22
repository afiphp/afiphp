<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Configuration;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\SoapException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use InvalidArgumentException;
use SimpleXMLElement;
use SoapFault;
use Throwable;
use UnexpectedValueException;

/** @internal SOAP call, ARCA error mapping and redacted diagnostics. Never retries a call. */
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
            $configuration->network,
        );
    }

    abstract protected function service(): string;

    public function execute(string $method, array $params = []): object
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $method)) {
            throw new InvalidArgumentException('Invalid SOAP method name.');
        }
        // No automatic retries: a lost authorization response may already be a valid invoice.
        try {
            $response = $this->transport->call($method, $params);
        } finally {
            // Optional diagnostics must never mask the fiscal result or the transport exception.
            try {
                $this->saveRequestAndResponse($method);
            } catch (Throwable) {
            }
        }
        if ($response instanceof SoapFault) {
            throw new SoapException($response);
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
            throw new WsException(
                'ARCA Error: ' . implode(PHP_EOL, $messages),
                (int) $errors[0]->Code,
                errors: $errors,
                response: $result,
            );
        }
        if (!property_exists($result, 'ResultGet')) {
            return $result;
        }
        if (!is_object($result->ResultGet)) {
            throw new InvalidResponseException('ARCA returned an empty or invalid ResultGet.', $result, $method);
        }

        return $result->ResultGet;
    }

    private function saveRequestAndResponse(string $method): void
    {
        if (!$this->configuration->network->saveSoapMessages) {
            return;
        }
        $suffix = bin2hex(random_bytes(8));
        $prefix = "{$this->configuration->cuit}-{$this->configuration->environment->value}-{$this->service()}";
        $messages = [
            'request' => $this->transport->getLastRequest(),
            'response' => $this->transport->getLastResponse(),
        ];
        foreach ($messages as $type => $xml) {
            $redacted = $xml === null || $xml === '' ? null : self::redact($xml);
            if ($redacted !== null) {
                $this->configuration->storage->writeDiagnostic("{$prefix}-{$method}-{$type}-{$suffix}.xml", $redacted);
            }
        }
    }

    /** Never persist credentials, CMS signatures or embedded WSAA tickets. */
    private static function redact(string $xml): ?string
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
            if ($document === false) {
                return null;
            }
            $secrets =
                '//*[local-name()="Token" or local-name()="Sign" or local-name()="token" '
                . 'or local-name()="sign" or local-name()="in0" or local-name()="loginCmsReturn"]';
            foreach ($document->xpath($secrets) ?: [] as $node) {
                $node[0] = '[REDACTED]';
            }

            return $document->asXML() ?: null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
