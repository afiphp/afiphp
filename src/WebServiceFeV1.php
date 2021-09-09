<?php

namespace Afiphp;

class WebServiceFeV1 extends WebServiceAfip
{
    const WSDL_PRODUCCION = "/wsdl/produccion/wsfev1.wsdl";
    const URL_PRODUCCION = "https://servicios1.afip.gov.ar/wsfev1/service.asmx";
    const WSDL_HOMOLOGACION = "/wsdl/homologacion/wsfev1.wsdl";
    const URL_HOMOLOGACION = "https://wswhomo.afip.gov.ar/wsfev1/service.asmx";

    public function __construct(int $cuit, bool $production = false) {
        $this->serviceName = "wsfe";
        $this->cuit = $cuit;
        
        if ($production) {
            $this->wsdl = Wsfev1::WSDL_PRODUCCION;
            $this->url = Wsfev1::URL_PRODUCCION;
        } else {
            $this->wsdl = Wsfev1::WSDL_HOMOLOGACION;
            $this->url = Wsfev1::URL_HOMOLOGACION;
        }
        $this->initializeSoapClient(SOAP_1_2);
    }
}