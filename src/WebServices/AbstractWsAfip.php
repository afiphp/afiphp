<?php

namespace Afiphp\WebServices;

use SoapClient;
use Exception;
use Afiphp\Exceptions\WsException;

abstract class AbstractWsAfip
{
    const WSFEV1 = 'wsfe';
    const WSAA = 'wsaa';

    const ENV_SANDBOX = 'sandbox';
    const ENV_PRODUCTION = 'production';

    const SOAP_URLS = [
        self::ENV_PRODUCTION => [
            self::WSFEV1 => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
            self::WSAA => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
        ],

        self::ENV_SANDBOX => [
            self::WSFEV1 => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            self::WSAA => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
        ],
    ];

    protected string $cuit;
    protected bool $isSandbox;
    protected string $certificateFilename;
    protected string $privateKeyFilename;
    protected string $xmlPath;
    protected string $passphrase;
    protected SoapClient $soapClient;

    /**
     * Get service name
     */
    public abstract function getServiceName(): string;

    /**
     * Web Service Afip
     *
     * @param string $cuit
     * @param string|array $paths
     * @param string $passphrase
     * @param boolean $isSandbox
     */
    public function __construct(
        string $cuit,
        $paths,
        string $passphrase = '',
        bool $isSandbox = true
    ) {
        $this->cuit = $cuit;
        $this->isSandbox = $isSandbox;
        $this->passphrase = $passphrase;

        if (is_array($paths)) {
            $this->certificateFilename = $paths['certificate'] ?? null;
            $this->privateKeyFilename = $paths['private_key'] ?? null;
            $this->xmlPath = rtrim($paths['xml'] ?? null, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        if (is_string($paths)) {
            $paths = rtrim($paths, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            $this->certificateFilename = "{$paths}{$cuit}.crt";
            $this->privateKeyFilename = "{$paths}{$cuit}.key";
            $this->xmlPath = "{$paths}xml" . DIRECTORY_SEPARATOR;

            if (!file_exists($this->xmlPath)) {
                mkdir($this->xmlPath, 0777);
            }
        }

        if (! is_writable($this->xmlPath)) {
            throw new Exception("Unable write on {$this->xmlPath}");
        }

        if (! file_exists($this->certificateFilename)) {
            throw new Exception("Unable to open {$this->certificateFilename}");
        }

        if (! file_exists($this->privateKeyFilename)) {
            throw new Exception("Unable to open {$this->privateKeyFilename}");
        }

        $this->soapClient = new SoapClient($this->getWsdlFilename(), [
            'soap_version' => SOAP_1_2,
            'location' => $this->getSoapUrl(),
            'trace' => true,
            'exceptions' => false,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'ciphers' => 'AES256-SHA',
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);
    }

    /**
     * Get CUIT
     */ 
    public function getCuit(): string
    {
        return $this->cuit;
    }

    /**
     * Get is sandbox
     */ 
    public function getEnvironment(): string
    {
        return $this->isSandbox ? self::ENV_SANDBOX: self::ENV_PRODUCTION;
    }


    /**
     * Get URL
     */
    public function getSoapUrl(): string
    {
        return self::SOAP_URLS[$this->getEnvironment()][$this->getServiceName()];
    }

    /**
     * Get Wsdl filename
     */
    public function getWsdlFilename(): string
    {
        return __DIR__ . "/Wsdl/{$this->getServiceName()}-{$this->getEnvironment()}.xml";
    }

    /**
     * Get certificate filename
     */
    public function getCertificateFilename(): string
    {
        return $this->certificateFilename;
    }

    /**
     * Get private key filename
     */
    public function getPrivateKeyFilename(): string
    {
        return $this->privateKeyFilename;
    }

    /**
     * Get path
     */
    protected function getPath(string $filename): string
    {
        return "{$this->xmlPath}{$this->cuit}-{$this->getServiceName()}-{$filename}";
    }

    /**
     * Log request and response
     */
    protected function saveRequestAndResponse(string $method): void
    {
        $now = date('YmdHis');

        file_put_contents($this->getPath("{$method}-request-{$now}.xml"), $this->soapClient->__getLastRequest());
        file_put_contents($this->getPath("{$method}-response-{$now}.xml"), $this->soapClient->__getLastRequest());
    }

    /**
     * Execute
     * @throws Exception
     */
    protected function execute(string $method, array $params = []): object
    {
        $response = $this->soapClient->{$method}($params);

        $this->saveRequestAndResponse($method);

        if (is_soap_fault($response)) {
            throw new Exception("SOAP Error: ({$response->faultcode}) {$response->faultstring}");
        }

        if (property_exists($response, "{$method}Result")) {
            $result = $response->{"{$method}Result"};
            
            if (isset($result->Errors)) {
                $message = implode(PHP_EOL, array_map(fn($error) => "({$error->Code}) {$error->Msg}", $result->Errors->Err ?? []));

                throw new WsException("AFIP Error: {$message}");
            }

            if (property_exists($result, "ResultGet")) {
                return $result->ResultGet;
            }

            return $result;
        }

        return $response;
    }
}