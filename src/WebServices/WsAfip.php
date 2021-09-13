<?php

namespace Afiphp\WebServices;

use stdClass;
use SoapClient;
use Exception;
use Afiphp\Exceptions\WsException;

abstract class WsAfip
{
    const WSFEV1 = 'wsfe';
    const WSAA = 'wsaa';

    protected string $cuit;
    protected bool $sandbox;
    protected string $certificateFilename;
    protected string $privateKeyFilename;
    protected string $xmlPath;
    protected string $passphrase;
    protected SoapClient $client;

    public abstract function getServiceName(): string;
    public abstract function getWsdl(): string;
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
        $this->sandbox = $sandbox;
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
            'trace' => 1,
            'exceptions' => 0,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'ciphers'=> 'AES256-SHA',
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]),
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);
    }

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function setCuit(string $value): void
    {
        $this->cuit = $value;
    }

    public function getSandbox(): bool
    {
        return $this->sandbox;
    }

    public function getCertificateFilename(): string
    {
        return $this->certificateFilename;
    }

    public function getPrivateKeyFilename(): string
    {
        return $this->privateKeyFilename;
    }

    protected function getPath(string $filename): string
    {
        return "{$this->xmlPath}{$this->cuit}-{$this->getServiceName()}-{$filename}";
    }

    protected function log(string $method): void
    {
        $now = date('YmdHis');

        file_put_contents($this->getPath("request-{$method}-{$now}.xml"), $this->client->__getLastRequest());
        file_put_contents($this->getPath("response-{$method}-{$now}.xml"), $this->client->__getLastRequest());
    }

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