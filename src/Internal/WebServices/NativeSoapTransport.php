<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\SoapException;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use Afiphp\NetworkOptions;
use SoapClient;
use SoapFault;

/** @internal */
final class NativeSoapTransport implements SoapTransport
{
    public function __construct(
        private string $wsdl,
        private string $url,
        private bool $soap11,
        private NetworkOptions $options,
        private ?SoapClient $client = null,
    ) {}

    private function client(): SoapClient
    {
        if ($this->client === null) {
            $this->client = new SoapClient($this->wsdl, [
                'soap_version' => $this->soap11 ? SOAP_1_1 : SOAP_1_2,
                'location' => $this->url,
                'trace' => $this->options->saveSoapMessages,
                'exceptions' => true,
                'connection_timeout' => $this->options->connectionTimeout,
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
                'typemap' => self::decimalTypeMap(),
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        // Opt-in for ARCA endpoints with legacy DH parameters; keep CA/hostname checks.
                        'ciphers' => $this->options->allowLegacyTls ? 'DEFAULT@SECLEVEL=1' : 'DEFAULT',
                    ],
                ]),
            ]);
        }

        return $this->client;
    }

    public function call(string $method, array $params): object
    {
        // ext-soap opens a socket directly and ignores the HTTP wrapper's timeout.
        // Limit the socket read timeout for this synchronous call, then restore the process setting.
        $previousTimeout = ini_get('default_socket_timeout');
        try {
            if (ini_set('default_socket_timeout', (string) $this->options->readTimeout) === false) {
                throw new \RuntimeException('Unable to configure the SOAP read timeout.');
            }
            // ext-soap may emit warnings as well as SoapFault (for example on TLS failure).
            // Expose the structured exception, not warning output in an application's response.
            $response = @$this->client()->__soapCall($method, [$params]);
        } catch (SoapFault $fault) {
            throw new SoapException($fault);
        } finally {
            ini_set('default_socket_timeout', $previousTimeout);
        }

        if ($response instanceof SoapFault) {
            throw new SoapException($response);
        }

        if (!is_object($response)) {
            throw new InvalidResponseException("Invalid SOAP response for {$method}.", null, $method);
        }

        return $response;
    }

    /**
     * @internal ext-soap's default xsd:double encoder truncates fiscal amounts at
     * PHP's default precision (14). Preserve decimal lexemes in BOTH directions;
     * raising a process-global precision setting is not a safe monetary contract.
     */
    public static function decimalTypeMap(): array
    {
        return [[
            'type_name' => 'double',
            'type_ns' => 'http://www.w3.org/2001/XMLSchema',
            'to_xml' => static function (mixed $value): string {
                if (!is_string($value) && !is_int($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value)) {
                    throw new \InvalidArgumentException(
                        'Fiscal SOAP decimals require exact non-negative decimal strings, not floats.',
                    );
                }
                return '<decimal>' . $value . '</decimal>';
            },
            'from_xml' => static function (string $xml): string {
                $previous = libxml_use_internal_errors(true);
                try {
                    if (stripos($xml, '<!DOCTYPE') !== false) {
                        throw new \UnexpectedValueException('Invalid decimal XML.');
                    }
                    $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
                    if ($element === false) {
                        throw new \UnexpectedValueException('Invalid decimal XML.');
                    }
                    return trim((string) $element);
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            },
        ]];
    }

    public function getLastRequest(): ?string
    {
        return $this->client?->__getLastRequest();
    }

    public function getLastResponse(): ?string
    {
        return $this->client?->__getLastResponse();
    }
}
