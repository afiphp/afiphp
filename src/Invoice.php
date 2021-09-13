<?php

namespace Afiphp;

use DateTime;
use Afiphp\WebServices\Contracts\Invoiceable;

class Invoice implements Invoiceable
{
    public int $type;
    public int $salesPoint;
    public ?int $number = null;
    public int $customerDocumentType;
    public string $customerDocumentNumber;
    public float $totalAmount;
    public float $untaxedAmount;
    public float $taxedAmount;
    public float $exemptAmount;
    public string $currencyType;
    public float $currencyRate;
    public int $conceptType;
    public DateTime $date;
    public ?DateTime $serviceFromDate = null;
    public ?DateTime $serviceToDate = null;
    public ?DateTime $paymentDueDate = null;
    protected array $tributes = [];
    protected array $taxes = [];
    protected array $optionals = [];
    protected array $relatedInvoices = [];

    public function __construct(
        int $type,
        int $salesPoint,
        float $taxedAmount,
        float $untaxedAmount,
        float $exemptAmount,
        int $customerDocumentType,
        string $customerDocumentNumber,
        DateTime $date = null,
        int $conceptType = 1,
        DateTime $serviceFromDate = null,
        DateTime $serviceToDate = null,
        DateTime $paymentDueDate = null,
        string $currencyType = 'PES',
        float $currencyRate = 1
    ) {
        $this->type = $type;
        $this->salesPoint = $salesPoint;
        $this->customerDocumentType = $customerDocumentType;
        $this->customerDocumentNumber = $customerDocumentNumber;
        $this->date = $date ?? new DateTime;
        $this->untaxedAmount = $untaxedAmount;
        $this->taxedAmount = $taxedAmount;
        $this->exemptAmount = $exemptAmount;
        $this->currencyType = $currencyType;
        $this->currencyRate = $currencyRate;
        $this->conceptType = $conceptType;
        $this->serviceFromDate = $serviceFromDate;
        $this->serviceToDate = $serviceToDate;
        $this->paymentDueDate = $paymentDueDate;
    }

    public function getTaxes(): array
    {
        return $this->taxes;
    }

    public function getTributes(): array
    {
        return $this->tributes;
    }

    public function addTax(int $code, float $taxableBase, float $amount): self
    {
        $this->taxes[] = (object)[
            'code' => $code,
            'taxableBase' => $taxableBase,
            'amount' => $amount,
        ];

        return $this;
    }

    public function addTribute(int $code, string $description, float $taxableBase, float $aliquot, float $amount): self
    {
        $this->tributes[] = (object)[
            'code' => $code,
            'description' => $description,
            'taxableBase' => $taxableBase,
            'aliquot' => $aliquot,
            'amount' => $amount,
        ];

        return $this;
    }

    public function addOptional(string $code, string $value): self
    {
        $this->optionals[] = (object)[
            'code' => $code,
            'value' => $value,
        ];

        return $this;
    }

    public function addRelatedInvoice(int $invoiceType, int $salesPoint, int $number, string $cuit = null, DateTime $date = null): self
    {
        $this->relatedInvoices[] = (object)[
            'invoiceType' => $invoiceType,
            'salesPoint' => $salesPoint,
            'number' => $number,
            'cuit' => $cuit,
            'date' => $date,
        ];

        return $this;
    }

    public function getTotalAmount(): float
    {
        return $this->untaxedAmount + $this->taxedAmount + $this->exemptAmount + $this->getTotalTaxes() + $this->getTotalTributes();
    }

    public function getTotalTaxes(): float
    {
        return (float)array_reduce(
            $this->taxes,
            fn(float $carry, $tax) => $tax->amount + $carry,
            0
        );
    }

    public function getTotalTributes(): float
    {
        return (float)array_reduce(
            $this->tributes,
            fn(float $carry, $tribute) => $tribute->amount + $carry,
            0
        );
    }

    public function toAfipService(string $serviceName): array
    {
        $details = [
            'CbteDesde' => $this->number,
            'CbteHasta' => $this->number,
            'CbteFch' => $this->date->format('Ymd'),
            'Concepto' => $this->conceptType,
            'DocTipo' => $this->customerDocumentType,
            'DocNro' => $this->customerDocumentNumber,
            'ImpTotal' => $this->getTotalAmount(),
            'ImpTotConc' => $this->untaxedAmount,
            'ImpNeto' => $this->taxedAmount,
            'ImpOpEx' => $this->exemptAmount,
            'ImpIVA' => $this->getTotalTaxes(),
            'ImpTrib' => $this->getTotalTributes(),
            'MonId' => $this->currencyType,
            'MonCotiz' => $this->currencyRate,
            'FchServDesde' => $this->serviceFromDate ? $this->serviceFromDate->format('Ymd') : '',
            'FchServHasta' => $this->serviceToDate ? $this->serviceToDate->format('Ymd') : '',
            'FchVtoPago' => $this->paymentDueDate ? $this->paymentDueDate->format('Ymd') : '',
        ];

        if ($this->tributes) {
            $details['Tributos'] = array_map(fn($tribute) => [
                'Id' => $tribute->code,
                'Desc' => $tribute->description,
                'BaseImp' => $tribute->taxableBase,
                'Alic' => $tribute->aliquot,
                'Importe' => $tribute->amount,
            ], $this->tributes);
        }

        if ($this->taxes) {
            $details['Iva'] = array_map(fn($tax) => [
                'Id' => $tax->code,
                'BaseImp' => $tax->taxableBase,
                'Importe' => $tax->amount,
            ], $this->taxes);
        }

        if ($this->optionals) {
            $details['Opcionales'] = array_map(fn($optional) => [
                'Id' => $optional->code,
                'Valor' => $optional->value,
            ], $this->optionals);
        }

        if ($this->relatedInvoices) {
            $details['CbtesAsoc'] = array_map(fn($relatedInvoice) => [
                'Tipo' => $relatedInvoice->invoiceType,
                'PtoVta' => $relatedInvoice->salesPoint,
                'Nro' => $relatedInvoice->number,
                'Cuit' => $relatedInvoice->cuit ?? '',
                'CbteFch' => $relatedInvoice->date ? DateTime::createFromFormat('Ymd', $relatedInvoice->date) : '',
            ], $this->relatedInvoices);
        }

        return [
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg' => 1,
                    'PtoVta' => $this->salesPoint,
                    'CbteTipo' => $this->type,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => $details,
                ],
            ],
        ];
    }

    public static function createProductInvoice(
        int $type,
        int $salesPoint,
        float $taxedAmount,
        float $untaxedAmount,
        float $exemptAmount,
        int $customerDocumentType,
        string $customerDocumentNumber,
        DateTime $date = null,
        string $currencyType = 'PES',
        float $currencyRate = 1
    ): self {
        return new static(
            $type,
            $salesPoint,
            $taxedAmount,
            $untaxedAmount,
            $exemptAmount,
            $customerDocumentType,
            $customerDocumentNumber,
            $date,
            1,
            null,
            null,
            null,
            $currencyType,
            $currencyRate,
        );
    }

    public static function createServiceInvoice(
        int $type,
        int $salesPoint,
        float $taxedAmount,
        float $untaxedAmount,
        float $exemptAmount,
        int $customerDocumentType,
        string $customerDocumentNumber,
        DateTime $serviceFromDate,
        DateTime $serviceToDate,
        DateTime $paymentDueDate,
        DateTime $date = null,
        string $currencyType = 'PES',
        float $currencyRate = 1
    ): self {
        return new static(
            $type,
            $salesPoint,
            $taxedAmount,
            $untaxedAmount,
            $exemptAmount,
            $customerDocumentType,
            $customerDocumentNumber,
            $date,
            2,
            $serviceFromDate,
            $serviceToDate,
            $paymentDueDate,
            $currencyType,
            $currencyRate
        );
    }

    public static function createFromAfipService(string $serviceName, object $data): self
    {
        $invoice = new static(
            (int)$data->CbteTipo,
            (int)$data->PtoVta,
            (float)$data->ImpNeto,
            (float)$data->ImpTotConc,
            (float)$data->ImpOpEx,
            (int)$data->DocTipo,
            $data->DocNro,
            DateTime::createFromFormat('Ymd', $data->CbteFch),
            (int)$data->Concepto,
            $data->FchServDesde ? DateTime::createFromFormat('Ymd', $data->FchServDesde) : null,
            $data->FchServHasta ? DateTime::createFromFormat('Ymd', $data->FchServHasta) : null,
            $data->FchVtoPago ? DateTime::createFromFormat('Ymd', $data->FchVtoPago) : null,
            $data->MonId,
            (float)$data->MonCotiz
        );

        $invoice->number = $data->CbteDesde;

        array_walk(
            $data->Iva->AlicIva ?? [],
            fn($tax) => $invoice->addTax(
                $tax->Id,
                $tax->BaseImp,
                $tax->Importe
            )
        );

        return $invoice;
    }
}