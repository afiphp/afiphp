<?php

namespace Afiphp;

class WebServiceFeV1 extends WebServiceAfip
{
    const WSDL_PRODUCCION = "/wsdl/produccion/wsfev1.wsdl";
    const URL_PRODUCCION = "https://servicios1.afip.gov.ar/wsfev1/service.asmx";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsfev1.wsdl";
    const URL_HOMOLOGACION = "https://wswhomo.afip.gov.ar/wsfev1/service.asmx";

    public string $cuit;
    public bool $sandbox;
    public string $certificatePath;
    public string $privateKeyPath;
    public string $xmlForlde;
    
    public function __construct(
        string $cuit,
        bool $sandbox = true,
        mixed $paths = null
    ) {
        $this->cuit = $cuit;
        $this->sandbox = $sandbox;

        if (is_array($paths)) {
            $this->certificatePath = $paths['certificate'];
            $this->privateKeyPath = $paths['privateKey'];
            $this->xmlForlder = $paths['xml'];
        }
        
        if (is_string($paths)) {
            $this->certificatePath = implode(DIRECTORY_SEPARATOR, [$paths, "$cuit.crt"]);
            $this->privateKeyPath = implode(DIRECTORY_SEPARATOR, [$paths, "$cuit.key"]);
            $this->xmlForlder = implode(DIRECTORY_SEPARATOR, [$paths, 'xml']);
        }

        if ($sandbox) {
            $this->wsdl = self::WSDL_PRODUCCION;
            $this->url = self::URL_PRODUCCION;
        } else {
            $this->wsdl = self::WSDL_HOMOLOGACION;
            $this->url = self::URL_HOMOLOGACION;
        }

        $this->initializeSoapClient(SOAP_1_2);
    }
}