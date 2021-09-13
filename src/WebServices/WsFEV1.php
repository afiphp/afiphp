<?php

namespace Afiphp\WebServices;

use DateTime;
use Afiphp\WebServices\Dtos\ServerStatus;
use Afiphp\WebServices\Dtos\SalesPoint;
use Afiphp\WebServices\Dtos\CAE;
use Afiphp\WebServices\Contracts\Invoiceable;

class WsFEV1 extends WsAfip
{
    protected $wsaa;

    /**
     * @inheritDoc
     */
    public function __construct(
        string $cuit,
        $paths,
        string $passphrase = '',
        bool $sandbox = true
    ) {
        parent::__construct($cuit, $paths, $passphrase, $sandbox);

        $this->wsaa = new WsAA($cuit, $paths, $passphrase, $sandbox);
    }

    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return WsAfip::WSFEV1;
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        return $this->isSandbox
            ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'
            : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    }

    /**
     * Get server status
     */
    public function getServerStatus(): ServerStatus
    {
        $response = $this->execute('FEDummy');

        return new ServerStatus(
            $response->AppServer === 'OK',
            $response->DbServer === 'OK',
            $response->AuthServer === 'OK'
        );
    }

    /**
     * Get last number
     */
    public function getLastNumber(int $invoiceType, int $salesPoint): int
    {
        $response = $this->execute('FECompUltimoAutorizado', [
            'CbteTipo' => $invoiceType,
            'PtoVta' => $salesPoint,
        ]);

        return (int)$response->CbteNro;
    }

    /**
     * Get sales points
     *
     * @return SalesPoint[]
     */
    public function getSalesPoints(): array
    {
        return array_map(fn($salesPoint) => new SalesPoint(
            $salesPoint->Nro,
            $salesPoint->EmisionTipo,
            $salesPoint->Bloqueado === 'S',
            $salesPoint->FchBaja === 'NULL' ? null : DateTime::createFromFormat('Ymd', $salesPoint->FchBaja),
        ), $this->execute('FEParamGetPtosVenta')->PtoVenta);
    }

    /**
     * Get invoice
     *
     * @return object
     */
    public function getInvoiceData(int $documentType, int $salesPoint, int $number): object
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
     */
    public function getCAE(int $documentType, int $salesPoint, int $number): CAE
    {
        $response = $this->execute('FECompConsultar', [
            'FeCompConsReq' => [
                'CbteTipo' => $documentType,
                'PtoVta' => $salesPoint,
                'CbteNro' => $number,
            ],
        ]);

        return new CAE(
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
     * 
     * @return CAE[]
     */
    public function createCAE(array $data): array
    {
        $response = $this->execute('FECAESolicitar', $data);

        $type = (int)$response->FeCabResp->CbteTipo;
        $salesPoint = (int)$response->FeCabResp->PtoVta;

        return array_map(fn($detail) => new CAE(
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
     * Create CAE from invoice
     *
     * @return CAE
     */
    public function createCAEFromInvoice(Invoiceable $invoice): CAE
    {
        $data = $invoice->toAfipService($this->getServiceName());

        if ($data['FeCAEReq']['FeDetReq']['FECAEDetRequest']['CbteDesde'] === null) {
            $nextNumber = $this->getLastNumber($data['FeCAEReq']['FeCabReq']['CbteTipo'], $data['FeCAEReq']['FeCabReq']['PtoVta']) + 1;

            $data['FeCAEReq']['FeDetReq']['FECAEDetRequest']['CbteDesde'] = $nextNumber;
            $data['FeCAEReq']['FeDetReq']['FECAEDetRequest']['CbteHasta'] = $nextNumber;
        }

        return $this->createCAE($data)[0];
    }

    /**
     * @inheritDoc 
     */
    protected function execute(string $method, array $params = []): object
    {
        $credentials = $this->wsaa->getCredentialsFor(WsAfip::WSFEV1);

        $params['Auth'] = [
            'Token' => $credentials->getToken(),
            'Sign' 	=> $credentials->getSign(),
            'Cuit' 	=> $this->cuit,
        ];

        return parent::execute($method, $params);
    }
}