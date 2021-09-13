<?php

namespace Afiphp\WebServices;

use SimpleXMLElement;
use Exception;

class WsAA extends WsAfip
{
    public function getServiceName(): string
    {
        return WsAfip::WSAA;
    }

    public function getUrl(): string
    {
        return $this->sandbox 
            ? 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms'
            : 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
    }

    protected function getTraFilename(string $serviceName): string
    {
        return "{$this->xmlPath}{$this->cuit}-{$serviceName}-tra.xml";
    }

    protected function getTaFilename(string $serviceName): string
    {
        return "{$this->xmlPath}{$this->cuit}-{$serviceName}-ta.xml";
    }

    protected function createTra(string $serviceName): string
    {
        $traFilename = $this->getTraFilename($serviceName);

        $tra = new SimpleXMLElement(trim(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <loginTicketRequest version="1.0">
            </loginTicketRequest>
        XML));

        $tra->addChild('header');
        $tra->header->addChild('uniqueId', date('U'));
        $tra->header->addChild('generationTime', date('c', date('U') - 600));
        $tra->header->addChild('expirationTime', date('c', date('U') + 600));
        $tra->addChild('service', $serviceName);
        $tra->asXML($traFilename);

        return $traFilename;
    }

    protected function signTra(string $traFilename): string
    {
        $outputPath = "{$traFilename}.tmp";
        $status = openssl_pkcs7_sign(
            $traFilename, 
            $outputPath,
            "file://{$this->certificateFilename}",
            ["file://{$this->privateKeyFilename}", $this->passphrase],
            [],
            !PKCS7_DETACHED
        );

        if (!$status) {
            throw new Exception("ERROR generating PKCS#7 signature");
        }

        $file = fopen($outputPath, 'r');
        $cms = '';
        $line = 0;

        while (!feof($file)) {
            $buffer = fgets($file);

            if ($line++ >= 4) {
                $cms .= $buffer;
            }
        }

        fclose($file);
        unlink($outputPath);

        return $cms;
    }

    protected function getLogin(string $cms): string
    {
        $response = $this->execute('loginCms', ['in0' => $cms]);

        return $response->loginCmsReturn;
    }

    protected function createTa(string $serviceName): string
    {
        $login = $this->getLogin(
            $this->signTra(
                $this->createTra($serviceName)
            )
        );

        $taFilename = $this->getTaFilename($serviceName);
        file_put_contents($taFilename, $login);

        return $taFilename;
    }

    public function getCredentialsFor(string $serviceName): Credentials
    {
        $taFilename = $this->getTaFilename($serviceName);
        $actualTime = $expirationTime = date('c');

        if (file_exists($taFilename)) {
            $taXml = simplexml_load_file($taFilename);
            $expirationTime = date('c', strtotime($taXml->header->expirationTime));
        } else {
            $expirationTime = $actualTime;
        }

        if ($actualTime >= $expirationTime) {
            $taFilename = $this->createTa($serviceName);
            $taXml = simplexml_load_file($taFilename);
        }

        return new Credentials($taXml->credentials->token, $taXml->credentials->sign);
    }
}