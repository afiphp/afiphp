<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use SoapClient;
use SoapFault;

/** @internal */
final class NativeSoapTransport implements SoapTransport
{
    private const CONNECTION_TIMEOUT = 10;

    public function __construct(
        private string $wsdl,
        private string $url,
        private bool $soap11,
        private int $timeout,
        private ?SoapClient $client = null,
        // ARCA's production WSFE still negotiates DH parameters that OpenSSL 3.x rejects at SECLEVEL 2.
        private bool $legacyTls = false,
    ) {}

    private function client(): SoapClient
    {
        if ($this->client === null) {
            $this->client = new SoapClient($this->wsdl, [
                'soap_version' => $this->soap11 ? SOAP_1_1 : SOAP_1_2,
                'location' => $this->url,
                'exceptions' => true,
                'connection_timeout' => min(self::CONNECTION_TIMEOUT, $this->timeout),
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
                'typemap' => self::decimalTypeMap(),
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        // SECLEVEL=1 only relaxes DH/RSA size checks; CA and hostname checks stay on.
                        'ciphers' => $this->legacyTls ? 'DEFAULT@SECLEVEL=1' : 'DEFAULT',
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
        // ext-soap reports TLS and DNS failures as warnings next to a generic SoapFault. Keep them to
        // classify the fault, and never print them into an application's response.
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            if (ini_set('default_socket_timeout', (string) $this->timeout) === false) {
                throw new \RuntimeException('Unable to configure the SOAP read timeout.');
            }
            $response = $this->client()->__soapCall($method, [$params]);
        } catch (SoapFault $fault) {
            throw Failures::fromFault($fault, implode(' ', $warnings));
        } finally {
            restore_error_handler();
            ini_set('default_socket_timeout', $previousTimeout);
        }

        if ($response instanceof SoapFault) {
            throw Failures::fromFault($response, implode(' ', $warnings));
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
}
