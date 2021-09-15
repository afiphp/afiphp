<?php

namespace Afiphp\WebServices;

use DateTime;
use Afiphp\WebServices\Contracts\WsFeV1InvoiceConverter;
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
     * Get sales points
     *
     * @return object[]
     * @throws Exception
     */
    public function getSalesPoints(): array
    {
        return $this->execute('FEParamGetPtosVenta')->PtoVenta;
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
     * Get CAE
     * @throws Exception
     */
    public function getCae(int $documentType, int $salesPoint, int $number): Cae
    {
        $response = $this->execute('FECompConsultar', [
            'FeCompConsReq' => [
                'CbteTipo' => $documentType,
                'PtoVta' => $salesPoint,
                'CbteNro' => $number,
            ],
        ]);

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
    public function createCae(array $data): array
    {
        if ($data['FeCAEReq']['FeDetReq']['FECAEDetRequest']['CbteDesde'] === null) {
            $nextNumber = $this->getNextInvoiceNumber($data['FeCAEReq']['FeCabReq']['CbteTipo'], $data['FeCAEReq']['FeCabReq']['PtoVta']);

            $data['FeCAEReq']['FeDetReq']['FECAEDetRequest']['CbteDesde'] = $nextNumber;
            $data['FeCAEReq']['FeDetReq']['FECAEDetRequest']['CbteHasta'] = $nextNumber;
        }

        $response = $this->execute('FECAESolicitar', $data);

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
     * Create CAE for invoice
     * @throws Exception
     */
    public function createCaeForInvoice(WsFeV1InvoiceConverter $invoice): Cae
    {
        return $this->createCae($invoice->toWsFeV1())[0];
    }
}