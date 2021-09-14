<?php

namespace Afiphp\WebServices;

use DateTime;
use Afiphp\WebServices\Contracts\Invoiceable;
use Afiphp\SalesPoint;
use Afiphp\Invoice;
use Afiphp\Cae;

class WsFev1 extends WsAfip
{
    protected $wsaa;

    /**
     * @inheritDoc
     */
    public function __construct(
        string $cuit,
        $paths,
        string $passphrase = '',
        bool $isSandbox = true
    ) {
        parent::__construct($cuit, $paths, $passphrase, $isSandbox);

        $this->wsaa = new WsAa($cuit, $paths, $passphrase, $isSandbox);
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
    public function getSoapUrl(): string
    {
        return $this->isSandbox
            ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'
            : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    }

    /**
     * Get server status
     *
     * @return ServerStatus[]
     */
    public function getServerStatus(): array
    {
        $response = $this->execute('FEDummy');

        return [
            'app' => $response->AppServer === 'OK',
            'db' => $response->DbServer === 'OK',
            'auth' => $response->AuthServer === 'OK',
        ];
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
     * Get invoice data
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
     * Get invoice
     *
     * @return object
     */
    public function getInvoice(int $documentType, int $salesPoint, int $number): Invoice
    {
        $response = $this->getInvoiceData($documentType, $salesPoint, $number);

        return Invoice::createFromAfipService($this->getServiceName(), $response);
    }

    /**
     * Get CAE
     */
    public function getCAE(int $documentType, int $salesPoint, int $number): Cae
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
     * 
     * @return Cae[]
     */
    public function createCAE(array $data): array
    {
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
     * Create CAE from invoice
     *
     * @return Cae
     */
    public function createCAEFromInvoice(Invoiceable $invoice): Cae
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
        $credentials = $this->wsaa->getCredentialsForWsFEV1();

        $params['Auth'] = [
            'Token' => $credentials->getToken(),
            'Sign' 	=> $credentials->getSign(),
            'Cuit' 	=> $this->cuit,
        ];

        return parent::execute($method, $params);
    }
}