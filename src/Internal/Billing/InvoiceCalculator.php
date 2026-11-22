<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Preview;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Billing\VatSubtotal;
use InvalidArgumentException;

/** @internal Port of facturas wsfe-amounts: group first, then integer Round Half Up. */
final class InvoiceCalculator
{
    /** RG 5616: conditions that a Responsable Inscripto must invoice with class A. */
    private const CLASS_A_RECEIVERS = [
        VatCondition::RESPONSABLE_INSCRIPTO,
        VatCondition::MONOTRIBUTISTA,
        VatCondition::MONOTRIBUTISTA_SOCIAL,
        VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO,
    ];

    public static function calculate(Invoice $invoice, VatCondition $issuer): Preview
    {
        if ($issuer === VatCondition::CONSUMIDOR_FINAL) {
            throw new InvalidArgumentException('A final consumer cannot be the issuer.');
        }
        // A note keeps the class of the voucher it adjusts, even if the issuer's condition changed since.
        $class = $invoice->original->class ?? match (true) {
            $issuer !== VatCondition::RESPONSABLE_INSCRIPTO => InvoiceClass::C,
            in_array($invoice->receiver->vatCondition, self::CLASS_A_RECEIVERS, true) => InvoiceClass::A,
            default => InvoiceClass::B,
        };
        $net = $vat = $exempt = $untaxed = 0;
        $groups = [];
        foreach ($invoice->items as $item) {
            if ($class === InvoiceClass::C) {
                if ($item->vat !== null) {
                    throw new InvalidArgumentException(
                        $issuer === VatCondition::RESPONSABLE_INSCRIPTO
                            ? 'A note on a class C voucher does not discriminate VAT: use Item::amount() without a VAT rate.'
                            : 'A Monotributista or Exento issuer does not discriminate VAT: use Item::amount() without a VAT rate.',
                    );
                }
                $net = Amounts::add($net, $item->amount);
                continue;
            }
            if ($item->vat === null) {
                throw new InvalidArgumentException(
                    $issuer === VatCondition::RESPONSABLE_INSCRIPTO
                        ? 'A Responsable Inscripto must give each item a VAT rate: Item::amount($cents, $rate) or Item::netAmount($cents, $rate).'
                        : 'A note on a class A or B voucher must give each item a VAT rate: Item::amount($cents, $rate) or Item::netAmount($cents, $rate).',
                );
            }
            if ($item->vat === VatRate::EXEMPT) {
                $exempt = Amounts::add($exempt, $item->amount);
                continue;
            }
            if ($item->vat === VatRate::UNTAXED) {
                $untaxed = Amounts::add($untaxed, $item->amount);
                continue;
            }
            $key = $item->vat->name;
            $groups[$key] ??= [
                'rate' => $item->vat,
                'net' => 0,
                'gross' => 0,
            ];
            $field = $item->includesVat ? 'gross' : 'net';
            $groups[$key][$field] = Amounts::add($groups[$key][$field], $item->amount);
        }
        ksort($groups);
        $subtotals = [];
        foreach ($groups as $group) {
            $basisPoints = self::basisPoints($group['rate']);
            $grossBase = Amounts::ratio($group['gross'], 10000, 10000 + $basisPoints);
            $base = Amounts::add($group['net'], $grossBase);
            $tax = Amounts::add(Amounts::ratio($group['net'], $basisPoints, 10000), $group['gross'] - $grossBase);
            if ($base === 0 && $tax === 0) {
                continue;
            }
            $net = Amounts::add($net, $base);
            $vat = Amounts::add($vat, $tax);
            $subtotals[] = new VatSubtotal($group['rate'], $base, $tax);
        }
        $otherTaxes = 0;
        foreach ($invoice->otherTaxes as $tax) {
            if ($tax->type === OtherTaxType::VAT_PERCEPTION && $class === InvoiceClass::C) {
                throw new InvalidArgumentException(
                    'Only a Responsable Inscripto can collect a VAT perception; class C cannot carry one.',
                );
            }
            $otherTaxes = Amounts::add($otherTaxes, $tax->amount);
        }
        $total = Amounts::validate($net + $vat + $exempt + $untaxed + $otherTaxes);
        if ($total === 0) {
            throw new InvalidArgumentException('An invoice total must be positive.');
        }

        return new Preview($class, $net, $vat, $exempt, $untaxed, $total, $subtotals, $otherTaxes);
    }

    public static function basisPoints(VatRate $rate): int
    {
        return match ($rate) {
            VatRate::ZERO => 0,
            VatRate::REDUCED_2_5 => 250,
            VatRate::REDUCED_5 => 500,
            VatRate::REDUCED_10_5 => 1050,
            VatRate::STANDARD_21 => 2100,
            VatRate::INCREASED_27 => 2700,
            default => throw new InvalidArgumentException('Exempt/untaxed is not a numeric VAT rate.'),
        };
    }
}
