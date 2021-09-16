<?php

namespace Afiphp\Entities;

use DateTime;
use Afiphp\WebServices\Contracts\WsFeV1Converter;
use Afiphp\WebServices\Cae;

class Invoice implements WsFeV1Converter
{
    protected int $type;
    protected int $salesPoint;
    protected ?int $number = null;
    protected Customer $customer;
    protected float $untaxedAmount;
    protected float $taxedAmount;
    protected float $exemptAmount;
    protected Currency $currency;
    protected int $conceptType;
    protected DateTime $date;
    protected ?DateTime $serviceFromDate = null;
    protected ?DateTime $serviceToDate = null;
    protected ?DateTime $paymentDueDate = null;
    protected ?Cae $cae = null;
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
        $this->date = $date ?? new DateTime;
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
     * Get type
     */ 
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * Set type
     */
    public function setType(int $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get sales point
     */ 
    public function getSalesPoint(): int
    {
        return $this->salesPoint;
    }

    /**
     * Set sales point
     */
    public function setSalesPoint(int $salesPoint): self
    {
        $this->salesPoint = $salesPoint;
        return $this;
    }

    /**
     * Get number
     */ 
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Set sales number
     */
    public function setNumber(int $number): self
    {
        $this->number = $number;
        return $this;
    }

    /**
     * Get customer
     */ 
    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * Set customer
     */
    public function setCustomer(Customer $customer): self
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * Get untaxed amount
     */ 
    public function getUntaxedAmount(): float
    {
        return $this->untaxedAmount;
    }

    /**
     * Set untaxed amount
     */
    public function setUntaxedAmount(float $untaxedAmount): self
    {
        $this->untaxedAmount = $untaxedAmount;
        return $this;
    }

    /**
     * Get taxed amount
     */ 
    public function getTaxedAmount(): float
    {
        return $this->taxedAmount;
    }
    
    /**
     * Set taxed amount
     */
    public function setTaxedAmount(float $taxedAmount): self
    {
        $this->taxedAmount = $taxedAmount;
        return $this;
    }

    /**
     * Get exempt amount
     */ 
    public function getExemptAmount(): float
    {
        return $this->exemptAmount;
    }

    /**
     * Set exempt amount
     */
    public function setExemptAmount(float $exemptAmount): self
    {
        $this->exemptAmount = $exemptAmount;
        return $this;
    }

    /**
     * Get currency
     */ 
    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    /**
     * Set currency
     */
    public function setCurrency(Currency $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    /**
     * Get concept type
     */ 
    public function getConceptType(): int
    {
        return $this->conceptType;
    }

    /**
     * Set concept type
     */
    public function setConceptType(int $conceptType): self
    {
        $this->conceptType = $conceptType;
        return $this;
    }

    /**
     * Get invoice date
     */ 
    public function getDate(): DateTime
    {
        return $this->date;
    }

    /**
     * Set invoice date
     */
    public function setDate(DateTime $date): self
    {
        $this->date = $date;
        return $this;
    }

    /**
     * Get service "from" date
     */ 
    public function getServiceFromDate(): ?DateTime
    {
        return $this->serviceFromDate;
    }

    /**
     * Set service "from" date
     */
    public function setServiceFromDate(?DateTime $serviceFromDate): self
    {
        $this->serviceFromDate = $serviceFromDate;
        return $this;
    }

    /**
     * Get service "to" date
     */ 
    public function getServiceToDate(): ?DateTime
    {
        return $this->serviceToDate;
    }
    
    /**
     * Set service "to" date
     */
    public function setServiceToDate(?DateTime $serviceToDate): self
    {
        $this->serviceToDate = $serviceToDate;
        return $this;
    }

    /**
     * Get payment due date
     */ 
    public function getPaymentDueDate(): ?DateTime
    {
        return $this->paymentDueDate;
    }

    /**
     * Set payment due date
     */
    public function setPaymentDueDate(?DateTime $paymentDueDate): self
    {
        $this->paymentDueDate = $paymentDueDate;
        return $this;
    }

    /**
     * Get CAE
     */ 
    public function getCae(): ?Cae
    {
        return $this->cae;
    }

    /**
     * Set CAE
     */
    public function setCae(?Cae $cae): self
    {
        $this->cae = $cae;
        return $this;
    }

    /**
     * Get taxes
     *
     * @return InvoiceTax[]
     */
    public function getTaxes(): array
    {
        return $this->taxes;
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
     * Clear taxes
     */
    public function clearTaxes(): self
    {
        $this->taxes = [];
        return $this;
    }

    /**
     * Get tributes
     *
     * @return InvoiceTribute[]
     */
    public function getTributes(): array
    {
        return $this->tributes;
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
     * Clear tributes
     */
    public function clearTributes(): self
    {
        $this->tributes = [];
        return $this;
    }

    /**
     * Get optionals
     *
     * @return InvoiceOptional[]
     */
    public function getOptionals(): array
    {
        return $this->optionals;
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
     * Clear optionals
     */
    public function clearOptionals(): self
    {
        $this->optionals = [];
        return $this;
    }

    /**
     * Get related invoices
     *
     * @return InvoiceRelated[]
     */
    public function getRelatedInvoices(): array
    {
        return $this->relatedInvoices;
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
     * Clear related invoices
     */
    public function clearRelatedInvoices(): self
    {
        $this->relatedInvoices = [];
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
            fn(float $carry, InvoiceTax $tax) => $tax->getAmount() + $carry,
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
            fn(float $carry, InvoiceTribute $tribute) => $tribute->getAmount() + $carry,
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
    public static function createFromWsfe(object $data): self
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
            array_map(fn($observation) => "({$observation->Code}) {$observation->Msg}", $data->Observaciones->Obs ?? [])
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
    public function toWsFeV1InvoiceRequest(): array
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
            'MonId' => $this->currency->getCode(),
            'MonCotiz' => $this->currency->getRate(),
            'FchServDesde' => $this->serviceFromDate ? $this->serviceFromDate->format('Ymd') : '',
            'FchServHasta' => $this->serviceToDate ? $this->serviceToDate->format('Ymd') : '',
            'FchVtoPago' => $this->paymentDueDate ? $this->paymentDueDate->format('Ymd') : '',
        ];

        if ($this->tributes) {
            $details['Tributos'] = array_map(fn(InvoiceTribute $tribute) => [
                'Id' => $tribute->getCode(),
                'Desc' => $tribute->getDescription(),
                'BaseImp' => $tribute->getTaxableBase(),
                'Alic' => $tribute->getAliquot(),
                'Importe' => $tribute->getAmount(),
            ], $this->tributes);
        }

        if ($this->taxes) {
            $details['Iva'] = array_map(fn(InvoiceTax $tax) => [
                'Id' => $tax->getCode(),
                'BaseImp' => $tax->getTaxableBase(),
                'Importe' => $tax->getAmount(),
            ], $this->taxes);
        }

        if ($this->optionals) {
            $details['Opcionales'] = array_map(fn(InvoiceOptional $optional) => [
                'Id' => $optional->getCode(),
                'Valor' => $optional->getValue(),
            ], $this->optionals);
        }

        if ($this->relatedInvoices) {
            $details['CbtesAsoc'] = array_map(function (InvoiceRelated $relatedInvoice) {
                $data = [
                    'Tipo' => $relatedInvoice->getInvoiceType(),
                    'PtoVta' => $relatedInvoice->getSalesPoint(),
                    'Nro' => $relatedInvoice->getNumber(),
                ];

                if ($cuit = $relatedInvoice->getCuit()) {
                    $data['Cuit'] = $cuit;
                }

                if ($date = $relatedInvoice->getDate()) {
                    $data['CbteFch'] = $date->format('Ymd');
                }

                return $data;
            }, $this->relatedInvoices);
        }

        return $details;
    }
}