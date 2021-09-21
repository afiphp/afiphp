<?php

namespace Afiphp\Webservices;

use Exception;
use SimpleXMLElement;

class WsAa extends AbstractWsAfip
{
    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return AbstractWsAfip::WSAA;
    }

    /**
     * Get login
     * @throws Exception
     */
    public function getLogin(string $signature): string
    {
        return $this->execute('loginCms', ['in0' => $signature])->loginCmsReturn;
    }

    /**
     * Get credentials for service
     * @throws Exception
     */
    public function getCredentials(string $serviceName): Credentials
    {
        $ta = $this->getTa($serviceName);

        return new Credentials($ta->credentials->token, $ta->credentials->sign);
    }

    /**
     * Get TA
     * @throws Exception
     */
    protected function getTa(string $serviceName): SimpleXMLElement
    {
        $filename = $this->getXmlPath("ta-{$serviceName}.xml");

        if (file_exists($filename)) {
            $xml = simplexml_load_file($filename);
            $actualTime = date('c');
            $expirationTime = date('c', strtotime($xml->header->expirationTime));

            if ($actualTime <= $expirationTime) {
                return $xml;
            }
        }

        $traFilename = $this->createTra($serviceName);
        $signature = $this->getSignature($traFilename);
        $login = $this->getLogin($signature);

        file_put_contents($filename, $login);

        return simplexml_load_file($filename);
    }

    /**
     * Create TRA
     */
    protected function createTra(string $serviceName): string
    {
        $filename = $this->getXmlPath("tra-{$serviceName}.xml");

        $tra = new SimpleXMLElement(trim(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <loginTicketRequest version="1.0">
            </loginTicketRequest>
        XML));

        $tra->addChild('header');
        $tra->header->addChild('uniqueId', date('U'));
        $tra->header->addChild('generationTime', date('c', (int)date('U') - 600));
        $tra->header->addChild('expirationTime', date('c', (int)date('U') + 600));
        $tra->addChild('service', $serviceName);
        $tra->asXML($filename);

        return $filename;
    }

    /**
     * Get signature
     * @throws Exception
     */
    protected function getSignature(string $filename): string
    {
        $signedFilename = "{$filename}.tmp";
        $status = openssl_pkcs7_sign(
            $filename,
            $signedFilename,
            "file://{$this->certificateFilename}",
            ["file://{$this->privateKeyFilename}", $this->passphrase],
            [],
            0
        );

        if (! $status) {
            throw new Exception("ERROR generating PKCS#7 signature");
        }

        $signedContents = file_get_contents($signedFilename);
        unlink($signedFilename);

        $begin = "Content-Transfer-Encoding: base64";

        return trim(substr($signedContents, strpos($signedContents, $begin) + strlen($begin)));
    }
}
