<?php

namespace Afiphp\WebServices;

use SoapClient;
use Exception;
use Afiphp\Exceptions\WsException;

abstract class WsAfip
{
    const WSFEV1 = 'wsfe';
    const WSAA = 'wsaa';

    protected string $cuit;
    protected bool $isSandbox;
    protected string $certificateFilename;
    protected string $privateKeyFilename;
    protected string $xmlPath;
    protected string $passphrase;
    protected SoapClient $client;

    /**
     * Get service name
     */
    public abstract function getServiceName(): string;

    /**
     * Get URL
     */
    public abstract function getUrl(): string;

    /**
     * Web Service Afip
     *
     * @param string $cuit
     * @param string|array $paths
     * @param string $passphrase
     * @param boolean $sandbox
     */
    public function __construct(
        string $cuit,
        $paths,
        string $passphrase = '',
        bool $sandbox = true
    ) {
        $this->cuit = $cuit;
        $this->isSandbox = $sandbox;
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

        $this->client = new SoapClient($this->getWsdl(), [
            'soap_version' => SOAP_1_2,
            'location' => $this->getUrl(),
            'trace' => true,
            'exceptions' => false,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);
    }

    /**
     * Get Wsdl
     */
    public function getWsdl(): string
    {
        $suffix = $this->isSandbox ? '-sandbox' : '';
        return __DIR__ . "/wsdl/{$this->getServiceName()}{$suffix}.xml";
    }

    /**
     * Get CUIT
     */
    public function getCuit(): string
    {
        return $this->cuit;
    }

    /**
     * Set CUIT
     */
    public function setCuit(string $value): void
    {
        $this->cuit = $value;
    }

    /**
     * Is sandbox
     */
    public function isSandbox(): bool
    {
        return $this->isSandbox;
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
     * Log
     */
    protected function log(string $method): void
    {
        $now = date('YmdHis');

        file_put_contents($this->getPath("{$method}-request-{$now}.xml"), $this->client->__getLastRequest());
        file_put_contents($this->getPath("{$method}-response-{$now}.xml"), $this->client->__getLastRequest());
    }

    /**
     * Execute
     */
    protected function execute(string $method, array $params = []): object
    {
        $response = $this->client->{$method}($params);

        $this->log($method);

        if (is_soap_fault($response)) {
            throw new Exception("Error SOAP: {$response->faultcode} - {$response->faultstring}");
        }

        if (property_exists($response, "{$method}Result")) {
            $result = $response->{"{$method}Result"};
            
            if (isset($result->Errors)) {
                throw new WsException($result->Errors->Err->Msg, $result->Errors->Err->Code);
            }

            if (property_exists($result, "ResultGet")) {
                return $result->ResultGet;
            }

            return $result;
        }

        return $response;
    }
}