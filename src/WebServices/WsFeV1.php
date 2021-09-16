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

        foreach ($requests as $i => $request) {
            if ($requests[$i]['CbteDesde'] === null) {
                $requests[$i]['CbteDesde'] = $nextNumber;
                $requests[$i]['CbteHasta'] = $nextNumber;

                $nextNumber++;
            }

            unset($requests[$i]['PtoVta']);
            unset($requests[$i]['CbteTipo']);
        }

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