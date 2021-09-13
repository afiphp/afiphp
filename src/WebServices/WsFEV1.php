<?php

namespace Afiphp\WebServices;

use DateTime;
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

    public function getServiceName(): string
    {
        return WsAfip::WSFEV1;
    }

    public function getUrl(): string
    {
        return $this->sandbox
            ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx'
            : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    }

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

    public function getServerStatus(): object
    {
        $response = $this->execute('FEDummy');

        return $response;
    }

    public function getLastNumber(int $invoiceType, int $salesPoint): int
    {
        $response = $this->execute('FECompUltimoAutorizado', [
            'CbteTipo' => $invoiceType,
            'PtoVta' => $salesPoint,
        ]);

        return (int)$response->CbteNro;
    }

    public function getSalesPoints(): array
    {
        return $this->execute('FEParamGetPtosVenta')->PtoVenta;
    }

    public function getInvoiceTypes(): array
    {
        return $this->execute('FEParamGetTiposCbte')->CbteTipo;
    }

    public function getConceptTypes(): array
    {
        return $this->execute('FEParamGetTiposConcepto')->ConceptoTipo;
    }

    public function getDocumentTypes(): array
    {
        return $this->execute('FEParamGetTiposDoc')->DocTipo;
    }

    public function getAliquotTypes(): array
    {
        return $this->execute('FEParamGetTiposIva')->IvaTipo;
    }

    public function getCurrenciesTypes(): array
    {
        return $this->execute('FEParamGetTiposMonedas')->Moneda;
    }

    public function getOptionsTypes(): array
    {
        return $this->execute('FEParamGetTiposOpcional')->OpcionalTipo;
    }

    /**
     * Get tax types
     *
     * @return array
     */
    public function getTaxTypes(): array
    {
        return $this->execute('FEParamGetTiposTributos')->TributoTipo;
    }

    /**
     * Get invoice
     *
     * @return object
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
}