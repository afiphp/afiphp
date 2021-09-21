<?php

namespace Afiphp\Entities;

use Afiphp\Webservices\Contracts\WsFev1InvoiceRequest;
use DateTime;

class Invoice implements WsFev1InvoiceRequest
{
    public int $type;
    public int $salesPoint;
    public ?int $number = null;
    public Customer $customer;
    public float $untaxedAmount;
    public float $taxedAmount;
    public float $exemptAmount;
    public Currency $currency;
    public int $conceptType;
    public DateTime $date;
    public ?DateTime $serviceFromDate = null;
    public ?DateTime $serviceToDate = null;
    public ?DateTime $paymentDueDate = null;
    public ?Cae $cae = null;
    public array $tributes = [];
    public array $taxes = [];
    public array $optionals = [];
    public array $relatedInvoices = [];

    public function __construct(
        int $type,
        int $salesPoint,
        float $taxedAmount,
        float $untaxedAmount,
        float $exemptAmount,
        Customer $customer,
        DateTime $date = null,
        int $conceptType = 1,
        DateTime $serviceFromDate = null,
        DateTime $serviceToDate = null,
        DateTime $paymentDueDate = null,
        Currency $currency = null
    ) {
        $this->type = $type;
        $this->salesPoint = $salesPoint;
        $this->customer = $customer;
        $this->date = $date ?? new DateTime();
        $this->untaxedAmount = $untaxedAmount;
        $this->taxedAmount = $taxedAmount;
        $this->exemptAmount = $exemptAmount;
        $this->currency = $currency ?? new Currency('PES', 1);
        $this->conceptType = $conceptType;
        $this->serviceFromDate = $serviceFromDate;
        $this->serviceToDate = $serviceToDate;
        $this->paymentDueDate = $paymentDueDate;
    }

    /**
     * Add tax
     */
    public function addTax(int $code, float $taxableBase, float $amount): self
    {
        $this->taxes[] = new InvoiceTax(
            $code,
            $taxableBase,
            $amount,
        );

        return $this;
    }

    /**
     * Add tribute
     */
    public function addTribute(int $code, string $description, float $taxableBase, float $aliquot, float $amount): self
    {
        $this->tributes[] = new InvoiceTribute(
            $code,
            $description,
            $taxableBase,
            $aliquot,
            $amount,
        );

        return $this;
    }

    /**
     * Add optional
     */
    public function addOptional(string $code, string $value): self
    {
        $this->optionals[] = new InvoiceOptional(
            $code,
            $value,
        );

        return $this;
    }

    /**
     * Add related invoice
     */
    public function addRelatedInvoice(int $invoiceType, int $salesPoint, int $number, string $cuit = null, DateTime $date = null): self
    {
        $this->relatedInvoices[] = new InvoiceRelated(
            $invoiceType,
            $salesPoint,
            $number,
            $cuit,
            $date,
        );

        return $this;
    }

    /**
     * Get total amount
     */
    public function getTotalAmount(): float
    {
        return $this->untaxedAmount + $this->taxedAmount + $this->exemptAmount + $this->getTotalTaxes() + $this->getTotalTributes();
    }

    /**
     * Get total taxes
     */
    public function getTotalTaxes(): float
    {
        return (float)array_reduce(
            $this->taxes,
            fn (float $carry, InvoiceTax $tax) => $tax->amount + $carry,
            0
        );
    }

    /**
     * Get total tributes
     */
    public function getTotalTributes(): float
    {
        return (float)array_reduce(
            $this->tributes,
            fn (float $carry, InvoiceTribute $tribute) => $tribute->amount + $carry,
            0
        );
    }

    /**
     * Create a product invoice
     */
    public static function createProductInvoice(
        int $type,
        int $salesPoint,
        float $taxedAmount,
        float $untaxedAmount,
        float $exemptAmount,
        Customer $customer,
        DateTime $date = null,
        Currency $currency = null
    ): self {
        return new static(
            $type,
            $salesPoint,
            $taxedAmount,
            $untaxedAmount,
            $exemptAmount,
            $customer,
            $date,
            1,
            null,
            null,
            null,
            $currency
        );
    }

    /**
     * Create a service invoice
     */
    public static function createServiceInvoice(
        int $type,
        int $salesPoint,
        float $taxedAmount,
        float $untaxedAmount,
        float $exemptAmount,
        Customer $customer,
        DateTime $serviceFromDate,
        DateTime $serviceToDate,
        DateTime $paymentDueDate,
        DateTime $date = null,
        Currency $currency = null
    ): self {
        return new static(
            $type,
            $salesPoint,
            $taxedAmount,
            $untaxedAmount,
            $exemptAmount,
            $customer,
            $date,
            2,
            $serviceFromDate,
            $serviceToDate,
            $paymentDueDate,
            $currency
        );
    }

    /**
     * Create an invoice from AFIP service data
     */
    public static function createFromWsFev1(object $data): self
    {
        $invoice = new static(
            (int)$data->CbteTipo,
            (int)$data->PtoVta,
            (float)$data->ImpNeto,
            (float)$data->ImpTotConc,
            (float)$data->ImpOpEx,
            new Customer((int)$data->DocTipo, $data->DocNro),
            DateTime::createFromFormat('Ymd', $data->CbteFch),
            (int)$data->Concepto,
            $data->FchServDesde ? DateTime::createFromFormat('Ymd', $data->FchServDesde) : null,
            $data->FchServHasta ? DateTime::createFromFormat('Ymd', $data->FchServHasta) : null,
            $data->FchVtoPago ? DateTime::createFromFormat('Ymd', $data->FchVtoPago) : null,
            new Currency($data->MonId, (float)$data->MonCotiz)
        );

        $invoice->number = $data->CbteDesde;

        $invoice->cae = new Cae(
            $data->Resultado,
            (int)$data->CbteTipo,
            (int)$data->PtoVta,
            (int)$data->CbteDesde,
            (int)$data->CbteHasta,
            $data->CodAutorizacion,
            DateTime::createFromFormat('Ymd', $data->FchVto),
            array_map(fn ($observation) => "({$observation->Code}) {$observation->Msg}", $data->Observaciones->Obs ?? [])
        );

        foreach ($data->Iva->AlicIva ?? [] as $iva) {
            $invoice->addTax(
                $iva->Id,
                $iva->BaseImp,
                $iva->Importe
            );
        }

        foreach ($data->Tributos->Tributo ?? [] as $tributo) {
            $invoice->addTribute(
                $tributo->Id,
                $tributo->Desc,
                $tributo->BaseImp,
                $tributo->Alic,
                $tributo->Importe
            );
        }

        return $invoice;
    }

    /**
     * @inheritDoc
     */
    public function toWsFev1InvoiceRequest(): array
    {
        $details = [
            'PtoVta' => $this->salesPoint,
            'CbteTipo' => $this->type,
            'CbteDesde' => $this->number,
            'CbteHasta' => $this->number,
            'CbteFch' => $this->date->format('Ymd'),
            'Concepto' => $this->conceptType,
            'DocTipo' => $this->customer->getDocumentType(),
            'DocNro' => $this->customer->getDocumentNumber(),
            'ImpTotal' => $this->getTotalAmount(),
            'ImpTotConc' => $this->untaxedAmount,
            'ImpNeto' => $this->taxedAmount,
            'ImpOpEx' => $this->exemptAmount,
            'ImpIVA' => $this->getTotalTaxes(),
            'ImpTrib' => $this->getTotalTributes(),
            'MonId' => $this->currency->code,
            'MonCotiz' => $this->currency->rate,
            'FchServDesde' => $this->serviceFromDate ? $this->serviceFromDate->format('Ymd') : '',
            'FchServHasta' => $this->serviceToDate ? $this->serviceToDate->format('Ymd') : '',
            'FchVtoPago' => $this->paymentDueDate ? $this->paymentDueDate->format('Ymd') : '',
        ];

        if ($this->tributes) {
            $details['Tributos'] = array_map(fn (InvoiceTribute $tribute) => [
                'Id' => $tribute->code,
                'Desc' => $tribute->description,
                'BaseImp' => $tribute->taxableBase,
                'Alic' => $tribute->aliquot,
                'Importe' => $tribute->amount,
            ], $this->tributes);
        }

        if ($this->taxes) {
            $details['Iva'] = array_map(fn (InvoiceTax $tax) => [
                'Id' => $tax->code,
                'BaseImp' => $tax->taxableBase,
                'Importe' => $tax->amount,
            ], $this->taxes);
        }

        if ($this->optionals) {
            $details['Opcionales'] = array_map(fn (InvoiceOptional $optional) => [
                'Id' => $optional->code,
                'Valor' => $optional->value,
            ], $this->optionals);
        }

        if ($this->relatedInvoices) {
            $details['CbtesAsoc'] = array_map(function (InvoiceRelated $relatedInvoice) {
                $data = [
                    'Tipo' => $relatedInvoice->invoiceType,
                    'PtoVta' => $relatedInvoice->salesPoint,
                    'Nro' => $relatedInvoice->number,
                ];

                if ($cuit = $relatedInvoice->cuit) {
                    $data['Cuit'] = $cuit;
                }

                if ($date = $relatedInvoice->date) {
                    $data['CbteFch'] = $date->format('Ymd');
                }

                return $data;
            }, $this->relatedInvoices);
        }

        return $details;
    }
}
