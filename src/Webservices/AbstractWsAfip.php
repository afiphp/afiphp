<?php

namespace Afiphp\Webservices;

use Afiphp\Exceptions\WsException;
use Exception;
use SoapClient;

abstract class AbstractWsAfip
{
    public const WSFEV1 = 'wsfe';
    public const WSAA = 'wsaa';

    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    public const SOAP_URLS = [
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
    protected bool $sandbox;
    protected bool $debug;
    protected string $certificateFilename;
    protected string $privateKeyFilename;
    protected string $xmlPath;
    protected string $tmpPath;
    protected string $passphrase;
    protected SoapClient $soapClient;
    protected $lastResponse;

    /**
     * Get service name
     */
    abstract public function getServiceName(): string;

    /**
     * Web Service Afip
     *
     * @param string $cuit
     * @param string|array $paths
     * @param string $passphrase
     * @param bool $sandbox
     * @param bool $debug
     */
    public function __construct(
        string $cuit,
        $paths,
        string $passphrase = null,
        bool $sandbox = true,
        bool $debug = false
    ) {
        $this->cuit = $cuit;
        $this->sandbox = $sandbox;
        $this->debug = $debug;
        $this->passphrase = $passphrase ?? '';

        if (is_array($paths)) {
            $this->certificateFilename = $paths['certificate'] ?? null;
            $this->privateKeyFilename = $paths['private_key'] ?? null;
            $this->xmlPath = rtrim($paths['xml'] ?? null, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $this->tmpPath = rtrim($paths['tmp'] ?? null, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        if (is_string($paths)) {
            $baseFolder = rtrim($paths, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            $this->certificateFilename = "{$baseFolder}{$cuit}.crt";
            $this->privateKeyFilename = "{$baseFolder}{$cuit}.key";
            $this->xmlPath = "{$baseFolder}xml" . DIRECTORY_SEPARATOR;

            if (! file_exists($this->xmlPath)) {
                mkdir($this->xmlPath, 0777);
            }

            if ($this->debug) {
                $this->tmpPath = "{$baseFolder}tmp" . DIRECTORY_SEPARATOR;

                if (! file_exists($this->tmpPath)) {
                    mkdir($this->tmpPath, 0777);
                }
            }
        }

        if (! is_writable($this->xmlPath)) {
            throw new Exception("Unable write on {$this->xmlPath}");
        }

        if ($this->debug && ! is_writable($this->tmpPath)) {
            throw new Exception("Unable write on {$this->tmpPath}");
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
        return $this->sandbox ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
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
     * Get TMP path
     */
    protected function getTmpPath(string $filename): string
    {
        return "{$this->tmpPath}{$this->cuit}-{$this->getServiceName()}-{$filename}";
    }

    /**
     * Get XML path
     */
    protected function getXmlPath(string $filename): string
    {
        return "{$this->xmlPath}{$this->cuit}-{$this->getServiceName()}-{$filename}";
    }

    /**
     * Log request and response
     */
    protected function saveRequestAndResponse(string $method): void
    {
        $now = date('YmdHis');

        file_put_contents($this->getTmpPath("{$method}-request-{$now}.xml"), $this->soapClient->__getLastRequest());
        file_put_contents($this->getTmpPath("{$method}-response-{$now}.xml"), $this->soapClient->__getLastResponse());
    }

    /**
     * Execute
     * @throws Exception
     */
    public function execute(string $method, array $params = []): object
    {
        $this->lastResponse = $this->soapClient->{$method}($params);

        if ($this->debug) {
            $this->saveRequestAndResponse($method);
        }

        if (is_soap_fault($this->lastResponse)) {
            throw new Exception("SOAP Error: ({$this->lastResponse->faultcode}) {$this->lastResponse->faultstring}");
        }

        if (property_exists($this->lastResponse, "{$method}Result")) {
            $result = $this->lastResponse->{"{$method}Result"};

            if (isset($result->Errors)) {
                $message = implode(PHP_EOL, array_map(fn ($e) => "({$e->Code}) {$e->Msg}", (array)$result->Errors->Err));

                throw new WsException("WS Error: {$message}");
            }

            if (property_exists($result, "ResultGet")) {
                return $result->ResultGet;
            }

            return $result;
        }

        return $this->lastResponse;
    }

    public function getLastRawResponse()
    {
        return $this->lastResponse;
    }
}
