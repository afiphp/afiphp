<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Currency;
use Afiphp\Billing\DocumentType;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Preview;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** @internal The only place where the public fiscal vocabulary becomes WSFE codes. */
final class InvoiceMapper
{
    public static function type(InvoiceClass $class, InvoiceKind $kind): int
    {
        return (
            match ($class) {
                InvoiceClass::A => 1,
                InvoiceClass::B => 6,
                InvoiceClass::C => 11,
            }
            + match ($kind) {
                InvoiceKind::INVOICE => 0,
                InvoiceKind::DEBIT_NOTE => 1,
                InvoiceKind::CREDIT_NOTE => 2,
            }
        );
    }

    public static function condition(VatCondition $condition): int
    {
        return match ($condition) {
            VatCondition::RESPONSABLE_INSCRIPTO => 1,
            VatCondition::EXENTO => 4,
            VatCondition::CONSUMIDOR_FINAL => 5,
            VatCondition::MONOTRIBUTISTA => 6,
            VatCondition::MONOTRIBUTISTA_SOCIAL => 13,
            VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO => 16,
            VatCondition::NO_ALCANZADO => 15,
        };
    }

    public static function documentType(DocumentType $type): int
    {
        return match ($type) {
            DocumentType::CUIT => 80,
            DocumentType::CUIL => 86,
            DocumentType::DNI => 96,
        };
    }

    public static function rate(VatRate $rate): int
    {
        return match ($rate) {
            VatRate::ZERO => 3,
            VatRate::REDUCED_10_5 => 4,
            VatRate::STANDARD_21 => 5,
            VatRate::INCREASED_27 => 6,
            VatRate::REDUCED_5 => 8,
            VatRate::REDUCED_2_5 => 9,
            default => throw new \LogicException('Exempt/untaxed has no VAT rate code.'),
        };
    }

    public static function rateFromCode(int $code): VatRate
    {
        foreach ([
            VatRate::ZERO,
            VatRate::REDUCED_2_5,
            VatRate::REDUCED_5,
            VatRate::REDUCED_10_5,
            VatRate::STANDARD_21,
            VatRate::INCREASED_27,
        ] as $rate) {
            if (self::rate($rate) === $code) {
                return $rate;
            }
        }
        throw new InvalidArgumentException('Unknown WSFE VAT rate code.');
    }

    public static function taxType(OtherTaxType $type): int
    {
        return match ($type) {
            OtherTaxType::NATIONAL => 1,
            OtherTaxType::PROVINCIAL => 2,
            OtherTaxType::MUNICIPAL => 3,
            OtherTaxType::INTERNAL => 4,
            OtherTaxType::GROSS_INCOME => 5,
            OtherTaxType::VAT_PERCEPTION => 6,
            OtherTaxType::GROSS_INCOME_PERCEPTION => 7,
            OtherTaxType::MUNICIPAL_PERCEPTION => 8,
            OtherTaxType::OTHER_PERCEPTION => 9,
            OtherTaxType::OTHER => 99,
        };
    }

    public static function currency(Currency $currency): string
    {
        return $currency->code === 'ARS' ? 'PES' : 'DOL';
    }

    public static function detail(Invoice $invoice, Preview $totals, string $issuerCuit): array
    {
        $detail = [
            'CbteFch' => $invoice->date->format('Ymd'),
            'Concepto' => $invoice->service === null ? 1 : ($invoice->service->includesProducts ? 3 : 2),
            'DocTipo' => self::documentType($invoice->receiver->documentType),
            'DocNro' => $invoice->receiver->document,
            'CondicionIVAReceptorId' => self::condition($invoice->receiver->vatCondition),
            'ImpTotal' => Amounts::decimal($totals->total),
            'ImpTotConc' => Amounts::decimal($totals->untaxed),
            'ImpNeto' => Amounts::decimal($totals->net),
            'ImpOpEx' => Amounts::decimal($totals->exempt),
            'ImpIVA' => Amounts::decimal($totals->vat),
            'ImpTrib' => Amounts::decimal($totals->otherTaxes),
            'MonId' => self::currency($invoice->currency),
            'MonCotiz' => $invoice->currency->exchangeRate,
        ];
        if ($invoice->currency->paidInSameCurrency !== null) {
            $detail['CanMisMonExt'] = $invoice->currency->paidInSameCurrency ? 'S' : 'N';
        }
        if ($invoice->service !== null) {
            $detail['FchServDesde'] = $invoice->service->from->format('Ymd');
            $detail['FchServHasta'] = $invoice->service->to->format('Ymd');
            $detail['FchVtoPago'] = $invoice->service->paymentDue->format('Ymd');
        }
        if ($totals->vatByRate !== []) {
            $detail['Iva'] = ['AlicIva' => array_map(static fn ($rate): array => [
                'Id' => self::rate($rate->rate),
                'BaseImp' => Amounts::decimal($rate->base),
                'Importe' => Amounts::decimal($rate->amount),
            ], $totals->vatByRate)];
        }
        if ($invoice->original !== null) {
            $original = $invoice->original;
            $detail['CbtesAsoc'] = [
                'CbteAsoc' => [[
                    'Tipo' => self::type($original->class, $original->kind),
                    'PtoVta' => $original->salesPoint,
                    'Nro' => $original->number,
                    'Cuit' => $issuerCuit,
                    'CbteFch' => $original->date->format('Ymd'),
                ]],
            ];
        }
        if ($invoice->period !== null) {
            $detail['PeriodoAsoc'] = [
                'FchDesde' => $invoice->period->from->format('Ymd'),
                'FchHasta' => $invoice->period->to->format('Ymd'),
            ];
        }
        if ($invoice->otherTaxes !== []) {
            $detail['Tributos'] = ['Tributo' => array_map(static fn ($tax): array => [
                'Id' => self::taxType($tax->type),
                'Desc' => $tax->description,
                'BaseImp' => Amounts::decimal($tax->base),
                'Alic' => $tax->percentage,
                'Importe' => Amounts::decimal($tax->amount),
            ], $invoice->otherTaxes)];
        }

        return $detail;
    }

    /** Parse a YYYY-MM-DD calendar date strictly: DateTimeImmutable otherwise normalizes invalid days. */
    public static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone('America/Argentina/Buenos_Aires'),
        );
        if (
            $date === false
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('Expected a valid YYYY-MM-DD calendar date.');
        }

        return $date;
    }
}
