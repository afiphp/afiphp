<?php

namespace Afiphp;

abstract class WebServiceAfip
{
    const MSG_AFIP_CONNECTION = "No pudimos comunicarnos con AFIP: ";
    const MSG_BAD_RESPONSE = "Respuesta mal formada";
    const MSG_ERROR_RESPONSE = "Respuesta con errores";
    const TA = '/token/TA.xml'; # Ticket de Acceso, from WSAA

    //************* VARIABLES *****************************
    protected $log_xmls = TRUE; # Logs de las llamadas
    protected int $cuit = 0;
    protected $client = NULL;
    protected $token = NULL;
    protected $sign = NULL;
    protected string $base_dir = __DIR__;
    protected string $wsdl = '';
    protected string $url = '';
    protected string $serviceName = '';

    protected function initializeSoapClient($version) {
        $filePath = $this->base_dir . $this->wsdl;

        if (!file_exists($filePath)) {
            throw new \Exception("Unable to open $filePath");
        }

        ini_set('soap.wsdl_cache_enabled', 0);
        ini_set('soap.wsdl_cache_ttl', 0);

        $context = stream_context_create([
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        ]);

        $this->client = new \SoapClient($filePath, [
            'soap_version' => $version,
            'location' => $this->url,
            'exceptions' => 1,
            'encoding' => 'ISO-8859-1',
            'features' => SOAP_USE_XSI_ARRAY_TYPE + SOAP_SINGLE_ELEMENT_ARRAYS,
            'trace' => 1,
            'stream_context' => $context,
        ]);

        if ($this->log_xmls) {
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName ."/tmp/functions.txt", print_r($this->client->__getFunctions(), TRUE));
            file_put_contents($this->base_dir . "/" . $this->cuit . "/" . $this->serviceName ."/tmp/types.txt", print_r($this->client->__getTypes(), TRUE));
        }
    }
}
