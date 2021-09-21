<?php

namespace Afiphp\Webservices;

use Exception;
use DateTime;
use Afiphp\Webservices\Contracts\WsFev1InvoiceRequest;
use Afiphp\Entities\SalesPoint;
use Afiphp\Entities\Invoice;
use Afiphp\Entities\Entity;
use Afiphp\Entities\Currency;
use Afiphp\Entities\Cae;

class WsFev1 extends AbstractWsAfipWithCredentials
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
     *
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
     *
     * @throws Exception
     */
    public function getLastAuthorizedInvoiceNumber(int $invoiceType, int $salesPoint): int
    {
        $response = $this->execute('FECompUltimoAutorizado', [
            'CbteTipo' => $invoiceType,
            'PtoVta' => $salesPoint,
        ]);

        return $response->CbteNro;
    }

    /**
     * Get next invoice number
     *
     * @throws Exception
     */
    public function getNextInvoiceNumber(int $invoiceType, int $salesPoint): int
    {
        return $this->getLastAuthorizedInvoiceNumber($invoiceType, $salesPoint) + 1;
    }

    /**
     * Get invoice data
     *
     * @throws Exception
     */
    public function getInvoice(int $documentType, int $salesPoint, int $number): Invoice
    {
        $response = $this->execute('FECompConsultar', [
            'FeCompConsReq' => [
                'CbteTipo' => $documentType,
                'PtoVta' => $salesPoint,
                'CbteNro' => $number,
            ],
        ]);

        return Invoice::createFromWsFev1($response);
    }

    /**
     * Get currency
     *
     * @throws Exception
     */
    public function getCurrency(string $code): Currency
    {
        $response = $this->execute('FEParamGetCotizacion', [
            'MonId' => $code,
        ]);

        return new Currency(
            $response->MonId,
            $response->MonCotiz,
            DateTime::createFromFormat('Ymd', $response->FchCotiz)
        );
    }

    /**
     * Get sales points
     *
     * @return SalesPoint[]
     * @throws Exception
     */
    public function getSalesPoints(): array
    {
        return array_map(fn ($x) => new SalesPoint(
            $x->Nro,
            $x->EmisionTipo,
            $x->Bloqueado === 'S',
            $x->FchBaja === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchBaja)
        ), $this->execute('FEParamGetPtosVenta')->PtoVenta);
    }

    /**
     * Get only available sales points
     *
     * @return SalesPoint[]
     * @throws Exception
     */
    public function getAvailableSalesPoints(): array
    {
        return array_values(array_filter(
            $this->getSalesPoints(),
            fn (SalesPoint $x) => !$x->isLocked
        ));
    }

    /**
     * Get invoice types
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getInvoiceTypes(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposCbte')->CbteTipo);
    }

    /**
     * Get concepts
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getConcepts(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposConcepto')->ConceptoTipo);
    }

    /**
     * Get identity document types
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getIdentityDocumentTypes(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposDoc')->DocTipo);
    }

    /**
     * Get taxes
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getTaxes(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposIva')->IvaTipo);
    }

    /**
     * Get currencies
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getCurrencies(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposMonedas')->Moneda);
    }

    /**
     * Get optionals
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getOptionals(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposOpcional')->OpcionalTipo);
    }

    /**
     * Get tributes
     *
     * @return Entity[]
     * @throws Exception
     */
    public function getTributes(): array
    {
        return array_map(fn ($x) => new Entity(
            $x->Id,
            $x->Desc,
            DateTime::createFromFormat('Ymd', $x->FchDesde),
            $x->FchHasta === 'NULL' ? null : DateTime::createFromFormat('Ymd', $x->FchHasta)
        ), $this->execute('FEParamGetTiposTributos')->TributoTipo);
    }

    /**
     * Get CAE
     * @throws Exception
     */
    public function getCae(int $documentType, int $salesPoint, int $number): Cae
    {
        $invoice = $this->getInvoice($documentType, $salesPoint, $number);

        return $invoice->cae;
    }

    /**
     * Create CAE
     *
     * @return Cae[]
     * @throws Exception
     */
    public function createCae(array $params): array
    {
        $response = $this->execute('FECAESolicitar', $params);

        $type = (int)$response->FeCabResp->CbteTipo;
        $salesPoint = (int)$response->FeCabResp->PtoVta;

        return array_map(fn ($detail) => new Cae(
            $detail->Resultado,
            $type,
            $salesPoint,
            (int)$detail->CbteDesde,
            (int)$detail->CbteHasta,
            $detail->CAE ?: null,
            $detail->CAEFchVto ? DateTime::createFromFormat('Ymd', $detail->CAEFchVto) : null,
            array_map(fn ($observation) => "({$observation->Code}) {$observation->Msg}", $detail->Observaciones->Obs ?? [])
        ), $response->FeDetResp->FECAEDetResponse);
    }

    /**
     * Create CAE from invoice
     *
     * @throws Exception
     */
    public function createCaeFromInvoice(WsFev1InvoiceRequest $entity): Cae
    {
        return $this->createCaeFromBatch([$entity])[0];
    }

    /**
     * Create CAE from batch
     *
     * @param WsFev1InvoiceRequest[] $entities
     * @return Cae[]
     * @throws Exception
     */
    public function createCaeFromBatch(array $entities): array
    {
        $requests = array_map(
            fn (WsFev1InvoiceRequest $entity) => $entity->toWsFev1InvoiceRequest(),
            $entities
        );

        if (! $requests) {
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
