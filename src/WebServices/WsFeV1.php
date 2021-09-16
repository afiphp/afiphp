<?php

namespace Afiphp\WebServices;

use DateTime;
use Afiphp\WebServices\Contracts\WsFeV1Converter;
use Exception;

class WsFeV1 extends AbstractWsAfipWithCredentials
{
    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return AbstractWsAfip::WSFEV1;
    }

    /**
     * Get server status
     * @throws Exception
     */
    public function getServerStatus(): array
    {
        $response = $this->execute('FEDummy');

        return [
            'App' => $response->AppServer === 'OK',
            'Db' => $response->DbServer === 'OK',
            'Auth' => $response->AuthServer === 'OK',
        ];
    }

    /**
     * Get last authorized invoice number
     * @throws Exception
     */
    public function getLastAuthorizedInvoiceNumber(int $invoiceType, int $salesPoint): int
    {
        $response = $this->execute('FECompUltimoAutorizado', [
            'CbteTipo' => $invoiceType,
            'PtoVta' => $salesPoint,
        ]);

        return (int)$response->CbteNro;
    }

    /**
     * Get next invoice number
     * @throws Exception
     */
    public function getNextInvoiceNumber(int $invoiceType, int $salesPoint): int
    {
        return $this->getLastAuthorizedInvoiceNumber($invoiceType, $salesPoint) + 1;
    }

    /**
     * Get invoice data
     * @throws Exception
     */
    public function getInvoice(int $documentType, int $salesPoint, int $number): object
    {
        return $this->execute('FECompConsultar', [
            'FeCompConsReq' => [
                'CbteTipo' => $documentType,
                'PtoVta' => $salesPoint,
                'CbteNro' => $number,
            ],
        ]);
    }

    /**
     * Recupera la cotizacion de la moneda consultada y su fecha.
     *
     * @param string $monId : Código de moneda de la que se solicita cotización
     *
     * Retorna la última cotización de la base de datos aduanera de la moneda ingresada.
     * MonCotiz double(4+6) Cotización de la moneda
     * MonId string(3) Código de moneda
     * FchCotiz string(8) Fecha de la cotización. Formato yyyymmdd
     */
    public function getCurrencyRate(string $currencyCode): object
    {
        return $this->execute('FEParamGetCotizacion', ['MonId' => $currencyCode]);
    }

    /*
     * Recupera el listado de puntos de venta registrados y su estado:
     * Permite consultar los puntos de venta para ambos tipos de Código de Autorización (CAE y CAEA) gestionados
     * previamente por la CUIT emisora.
     *
     * PtoVenta: Detalle de los tipos puntos de venta electrónicos:
     * Nro int(4) Punto de venta
     * EmisionTipo string(8) Identifica si es punto de venta para CAE o CAE
     * Bloqueado string(1) Indica si el punto de  venta  esta  bloqueado. De darse esta situación se
     *               deberá ingresar al ABM de puntos de venta a regularizar la situación Valores  S o N
     * FchBaja string(8) Indica la fecha de baja en caso de estarlo
     */
    public function getSalesPoints(): array
    {
        return $this->execute('FEParamGetPtosVenta')->PtoVenta;
    }

    public function getAvailableSalesPoints(): array
    {
        return array_values(array_filter(
            $this->getSalesPoints(),
            fn($salesPoint) => $salesPoint->Bloqueado === 'N'
        ));
    }

    /*
     * Recupera el listado de Tipos de Comprobantes utilizables en servicio de autorización.
     * Permite consultar los tipos de comprobantes habilitados en este WS.
     *
     * CbteTipo: Detalle de los tipos de comprobantes; esta compuesto por los siguientes campos:
     * Id int(3)  Código  de comprobante
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getInvoiceTypes(): array
    {
        return $this->execute('FEParamGetTiposCbte')->CbteTipo;
    }

    /*
     * Recupera el listado de identificadores para el campo Concepto.
     *
     * ConceptoTipo: Detalle de los tipos de conceptos; esta compuesto por los siguientes campos:
     * Id int(3)  Código  de concepto
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getConceptTypes(): array
    {
        return $this->execute('FEParamGetTiposConcepto')->ConceptoTipo;
    }

    /*
     * Recupera el listado de Tipos de Documentos utilizables en servicio de autorización.
     *
     * DocTipo: Retorna el universo de tipos de documentos disponibles en el presente WS;
     *               esta compuesto por los siguientes campos:
     * Id int(2)  Código de tipo de documento
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getDocumentTypes(): array
    {
        return $this->execute('FEParamGetTiposDoc')->DocTipo;
    }

    /*
     * Recupera el listado de Tipos de Iva utilizables en servicio de autorización:
     * Se obtiene la totalidad de alícuotas de IVA posibles de uso en el presente WS, detallando código y descripción.
     *
     * IvaTipo: Retorna el universo de tipos de documentos disponibles en el presente WS;
     *               esta compuesto por los siguientes campos:
     * Id int(2) Tipo de IVA
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getTaxes(): array
    {
        return $this->execute('FEParamGetTiposIva')->IvaTipo;
    }

    /*
     * Recupera el listado de monedas utilizables en servicio de autorización.
     *
     * Moneda: Retorna el universo de tipos de documentos disponibles en el presente WS;
     *               esta compuesto por los siguientes campos:
     * Id string(3) Código de moneda
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getCurrencies(): array
    {
        return $this->execute('FEParamGetTiposMonedas')->Moneda;
    }

    /*
     * Recupera el listado de identificadores para los campos Opcionales
     * Permite consultar los códigos y descripciones de los tipos de datos Opcionales que se  encuentran habilitados
     * para ser usados en el WS.
     *
     * OpcionalTipo: Detalle de los tipos de datos opcionales; esta compuesto por los siguientes campos:
     * Id string(4) Identificador de campo  opcional
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getOptionalTypes(): array
    {
        return $this->execute('FEParamGetTiposOpcional')->OpcionalTipo;
    }

    /*
     * Recupera el listado de los diferentes paises que pueden ser utilizados en el servicio de autorizacion.
     *
     * PaisTipo: Lista de paises; esta compuesto por los siguientes campos:
     * Id string(4) Código de país
     * Desc string(250) Descripción
     */
    public function getCountries(): array
    {
        return $this->execute('FEParamGetTiposPaises')->PaisTipo;
    }

    /*
     * Recupera el listado de los diferente tributos que pueden ser utilizados en el servicio de autorizacion.
     *
     * TributoTipo: Detalle de los tipos de tributos; esta compuesto por los siguientes campos:
     * Id string(2) Código de Tributo
     * Desc string(250) Descripción
     * FchDesde string(8) Fecha de vigencia desde
     * FchHasta string(8) Fecha de vigencia hasta
     */
    public function getTributes(): array
    {
        return $this->execute('FEParamGetTiposTributos')->TributoTipo;
    }

    /**
     * Get CAE
     * @throws Exception
     */
    public function getCae(int $documentType, int $salesPoint, int $number): Cae
    {
        $response = $this->getInvoice($documentType, $salesPoint, $number);

        return new Cae(
            $response->Resultado,
            (int)$response->CbteTipo,
            (int)$response->PtoVta,
            (int)$response->CbteDesde,
            (int)$response->CbteHasta,
            $response->CodAutorizacion,
            DateTime::createFromFormat('Ymd', $response->FchVto),
            array_map(fn($observation) => "({$observation->Code}) {$observation->Msg}", $response->Observaciones->Obs ?? [])
        );
    }

    /**
     * Create CAE
     * @return Cae[]
     * @throws Exception
     */
    public function createCae(array $params): array
    {
        $response = $this->execute('FECAESolicitar', $params);

        $type = (int)$response->FeCabResp->CbteTipo;
        $salesPoint = (int)$response->FeCabResp->PtoVta;
        
        return array_map(fn($detail) => new Cae(
            $detail->Resultado,
            $type,
            $salesPoint,
            (int)$detail->CbteDesde,
            (int)$detail->CbteHasta,
            $detail->CAE ?: null,
            $detail->CAEFchVto ? DateTime::createFromFormat('Ymd', $detail->CAEFchVto) : null,
            array_map(fn($observation) => "({$observation->Code}) {$observation->Msg}", $detail->Observaciones->Obs ?? [])
        ), $response->FeDetResp->FECAEDetResponse);
    }

    /**
     * Create CAE from ...
     * @throws Exception
     */
    public function createCaeFrom(WsFeV1Converter $entity): Cae
    {
        return $this->createCaeFromBatch([$entity])[0];
    }

    /**
     * Create CAE from batch
     *
     * @param WsFeV1Converter[] $entities
     * @return Cae[]
     * @throws Exception
     */
    public function createCaeFromBatch(array $entities): array
    {
        $requests = array_map(
            fn(WsFeV1Converter $entity) => $entity->toWsFeV1InvoiceRequest(),
            $entities
        );

        if ( ! $requests) {
            return [];
        }

        $salesPoint = $requests[0]['PtoVta'];
        $invoiceType = $requests[0]['CbteTipo'];

        $nextNumber = $this->getNextInvoiceNumber($invoiceType, $salesPoint);

        foreach ($requests as &$request) {
            if ($request['CbteDesde'] === null) {
                // Post-increment: Returns $nextNumber, then increments $nextNumber by one.
                $request['CbteDesde'] = $request['CbteHasta'] = $nextNumber++;
            }

            unset($request['PtoVta']);
            unset($request['CbteTipo']);
        }

        // Reference of a $value and the last array element remain even after the foreach loop.
        // It is recommended to destroy it by unset(). 
        unset($request);

        $params = [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg' => count($requests),
                    'PtoVta' => $salesPoint,
                    'CbteTipo' => $invoiceType,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => $requests,
                ],
            ],
        ];

        return $this->createCae($params);
    }
}