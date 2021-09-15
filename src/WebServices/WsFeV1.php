<?php

namespace Afiphp\WebServices;

use DateTime;
use Afiphp\WebServices\Contracts\Invoiceable;

class WsFeV1 extends WsAfipWithCredentials
{
    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return WsAfip::WSFEV1;
    }

    /**
     * Get server status
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
     */
    public function getNextInvoiceNumber(int $invoiceType, int $salesPoint): int
    {
        return $this->getLastAuthorizedInvoiceNumber($invoiceType, $salesPoint) + 1;
    }

    /**
     * Get sales points
     *
     * @return object[]
     */
    public function getSalesPoints(): array
    {
        return $this->execute('FEParamGetPtosVenta')->PtoVenta;
    }

    /**
     * Get invoice data
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
     * Create CAE
     * @return Cae[]
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
     */
    public function createCaeForInvoice(Invoiceable $invoice): Cae
    {
        return $this->createCae($invoice->toAfipService($this->getServiceName()))[0];
    }
}