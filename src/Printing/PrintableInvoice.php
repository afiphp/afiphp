<?php

declare(strict_types=1);

namespace Afiphp\Printing;

use Afiphp\Billing\Currency;
use Afiphp\Billing\DocumentType;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Billing\VatSubtotal;
use Afiphp\Internal\Billing\Amounts;
use Afiphp\Internal\Billing\InvoiceCalculator;
use Afiphp\Internal\Billing\InvoiceMapper;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Everything a printed class A, B or C voucher must show (RG 1415, Anexo II; RG 4291; Ley 27.743;
 * Ley 27.618), derived and checked against what ARCA authorized. No I/O and no layout: render it with
 * any template. Amounts are integer cents of the voucher's currency.
 */
final readonly class PrintableInvoice
{
    public const CODE_LABEL = 'Código Nº';
    public const ACTIVITIES_START_LABEL = 'INICIO DE ACTIVIDADES';
    public const CAE_LABEL = 'C.A.E. N°';
    public const CAE_DUE_DATE_LABEL = 'Fecha Vto.:';
    /** The CAE due date goes in type of at least this size. */
    public const CAE_DUE_DATE_MIN_FONT_SIZE_PT = 12;
    public const TRANSPARENCY_TITLE = 'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)';
    public const VAT_CONTAINED_LABEL = 'IVA Contenido';
    public const OTHER_NATIONAL_INDIRECT_TAXES_LABEL = 'Otros Impuestos Nacionales Indirectos';
    /** RG 1415, art. 15, inc. a), as replaced by RG 5003: class A to a Monotributista. */
    public const MONOTRIBUTO_CREDIT_LEGEND =
        'El crédito fiscal discriminado en el presente comprobante, sólo podrá ser computado a efectos del '
            . 'Régimen de Sostenimiento e Inclusión Fiscal para Pequeños Contribuyentes de la Ley Nº 27.618';
    public const OBSERVATIONS_LABEL = 'Observaciones de ARCA:';
    public const INFORMED_CBU_LEGEND = 'PAGO EN CBU INFORMADA';

    /**
     * @param list<string> $remitos
     * @param list<PrintableLine> $lines
     * @param list<VatSubtotal> $vatByRate Class A only; empty otherwise.
     * @param list<OtherTax> $otherTaxes
     * @param list<string> $legends Conditional legends, in print order.
     */
    private function __construct(
        public InvoiceClass $letter,
        public string $code,
        public string $title,
        public ?string $letterLegend,
        public string $number,
        public DateTimeImmutable $date,
        public IssuerDetails $issuer,
        public string $issuerConditionLegend,
        public string $receiverConditionLegend,
        public ?string $receiverName,
        public ?string $receiverAddress,
        public ?string $receiverDocumentLabel,
        public ?string $receiverDocument,
        public string $saleConditions,
        public array $remitos,
        public ?ServicePeriod $service,
        public Currency $currency,
        public array $lines,
        public int $subtotal,
        public int $exempt,
        public int $untaxed,
        public array $vatByRate,
        public array $otherTaxes,
        public int $total,
        public ?int $transparencyVat,
        public ?int $transparencyOtherTaxes,
        public string $cae,
        public DateTimeImmutable $caeDueDate,
        public string $qr,
        public array $legends,
    ) {}

    /**
     * @param Invoice $invoice The invoice exactly as it was issued.
     * @param IssueResult $result What issue() answered, or voucher() for a reprint.
     * @param array<array-key, mixed> $lines One PrintedLine per Item of $invoice, in the same order.
     * @param string $saleConditions Contado, cuenta corriente, or whatever applies.
     * @param array<array-key, mixed> $remitos Strings: the delivery notes linked to the operation, if any.
     * @throws InvalidArgumentException When data is missing or the invoice is not the one ARCA authorized.
     */
    public static function build(
        Invoice $invoice,
        IssueResult $result,
        IssuerDetails $issuer,
        array $lines,
        string $saleConditions,
        ?ReceiverDetails $receiver = null,
        array $remitos = [],
    ): self {
        $class = $result->class;
        self::assertSameVoucher($invoice, $result, $issuer);
        if (!array_is_list($lines) || count($lines) !== count($invoice->items)) {
            throw new InvalidArgumentException('Give exactly one PrintedLine per Item, in the same order.');
        }
        foreach ($lines as $line) {
            if (!$line instanceof PrintedLine) {
                throw new InvalidArgumentException('Lines must be PrintedLine instances.');
            }
        }
        if (trim($saleConditions) === '') {
            throw new InvalidArgumentException('The sale conditions are required on a printed voucher.');
        }
        foreach ($remitos as $remito) {
            if (!is_string($remito) || trim($remito) === '') {
                throw new InvalidArgumentException('Each remito must be a non-empty string.');
            }
        }
        $buyer = $invoice->receiver;
        $identified = $buyer->vatCondition !== VatCondition::CONSUMIDOR_FINAL;
        if ($identified && ($receiver?->name === null || $receiver->address === null)) {
            throw new InvalidArgumentException(
                "The receiver's name and address are required on a printed voucher unless it is a final consumer.",
            );
        }

        $amounts = self::lineAmounts($invoice, $result);
        $printed = [];
        foreach ($lines as $index => $line) {
            [$quantity, $unitPrice] = self::quantity($line, $amounts[$index]);
            $printed[] = new PrintableLine(
                $line->description,
                $quantity,
                $unitPrice,
                $line->discount,
                $amounts[$index],
                $class === InvoiceClass::A ? $invoice->items[$index]->vat : null,
                $line->code,
                $line->unit,
            );
        }
        $totals = $result->totals;
        $subtotal = $class === InvoiceClass::A ? $totals->net : array_sum($amounts);

        $transparency =
            $class === InvoiceClass::B
            && in_array(
                $buyer->vatCondition,
                [
                    VatCondition::CONSUMIDOR_FINAL,
                    VatCondition::EXENTO,
                    VatCondition::NO_ALCANZADO,
                ],
                true,
            );
        $nationalIndirect = 0;
        foreach ($invoice->otherTaxes as $tax) {
            if (in_array($tax->type, [OtherTaxType::NATIONAL, OtherTaxType::INTERNAL], true)) {
                $nationalIndirect += $tax->amount;
            }
        }

        $legends = [];
        if ($class === InvoiceClass::A) {
            if (in_array(
                $buyer->vatCondition,
                [
                    VatCondition::MONOTRIBUTISTA,
                    VatCondition::MONOTRIBUTISTA_SOCIAL,
                    VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO,
                ],
                true,
            )) {
                $legends[] = self::MONOTRIBUTO_CREDIT_LEGEND;
            }
            $codes = [];
            foreach ($result->observations as $observation) {
                if (preg_match('/^\(([^)]+)\)/', $observation, $match)) {
                    $codes[$match[1]] = true;
                }
            }
            if ($codes !== []) {
                $legends[] = self::OBSERVATIONS_LABEL . ' ' . implode(', ', array_keys($codes));
            }
        }

        $type = InvoiceMapper::type($class, $result->kind);

        return new self(
            $class,
            sprintf('%03d', $type),
            match ($result->kind) {
                InvoiceKind::INVOICE => 'FACTURA',
                InvoiceKind::DEBIT_NOTE => 'NOTA DE DÉBITO',
                InvoiceKind::CREDIT_NOTE => 'NOTA DE CRÉDITO',
            },
            $class === InvoiceClass::A && $issuer->paymentToInformedCbu ? self::INFORMED_CBU_LEGEND : null,
            sprintf('%05d-%08d', $result->salesPoint, $result->number),
            $result->date,
            $issuer,
            self::conditionLegend($issuer->vatCondition),
            $buyer->vatCondition === VatCondition::CONSUMIDOR_FINAL
                ? 'A CONSUMIDOR FINAL'
                : self::conditionLegend($buyer->vatCondition),
            $receiver?->name,
            $receiver?->address,
            match ($buyer->documentType) {
                DocumentType::CUIT => 'CUIT',
                DocumentType::CUIL => 'CUIL',
                DocumentType::DNI => 'DNI',
            },
            $buyer->document,
            $saleConditions,
            array_values($remitos),
            $invoice->service,
            $invoice->currency,
            $printed,
            $subtotal,
            $totals->exempt,
            $totals->untaxed,
            $class === InvoiceClass::A ? $totals->vatByRate : [],
            $invoice->otherTaxes,
            $totals->total,
            $transparency ? $totals->vat : null,
            $transparency ? $nationalIndirect : null,
            $result->authorization->cae,
            $result->authorization->expiresOn,
            $result->qr,
            $legends,
        );
    }

    private static function assertSameVoucher(Invoice $invoice, IssueResult $result, IssuerDetails $issuer): void
    {
        $registered = $issuer->vatCondition === VatCondition::RESPONSABLE_INSCRIPTO;
        if ($registered === ($result->class === InvoiceClass::C)) {
            throw new InvalidArgumentException(
                "A {$issuer->vatCondition->name} issuer does not issue class {$result->class->value} vouchers.",
            );
        }
        $payload = json_decode(
            (string) base64_decode(substr($result->qr, (int) strpos($result->qr, '?p=') + 3), true),
            true,
        );
        if (!is_array($payload) || (string) ($payload['cuit'] ?? '') !== ltrim($issuer->cuit, '0')) {
            throw new InvalidArgumentException('The voucher was not issued under the issuer\'s CUIT.');
        }
        $totals = InvoiceCalculator::calculate($invoice, $issuer->vatCondition);
        if (
            $invoice->kind !== $result->kind
            || $invoice->salesPoint !== $result->salesPoint
            || $totals != $result->totals
            || $result->receiver !== null && $result->receiver != $invoice->receiver
            || $result->currency != $invoice->currency
        ) {
            throw new InvalidArgumentException('The invoice is not the one ARCA authorized in this result.');
        }
    }

    /**
     * Each item's printed amount: as issued on class C, without VAT on A and with VAT on B. Each rate's
     * lines add up exactly to what ARCA authorized for it; a rounding cent goes to a derived amount first.
     * @return list<int>
     */
    private static function lineAmounts(Invoice $invoice, IssueResult $result): array
    {
        $class = $result->class;
        $amounts = [];
        $groups = [];
        foreach ($invoice->items as $index => $item) {
            $rate = $item->vat;
            if (
                $class === InvoiceClass::C
                || $rate === null
                || $rate === VatRate::EXEMPT
                || $rate === VatRate::UNTAXED
            ) {
                $amounts[$index] = $item->amount;
                continue;
            }
            $points = InvoiceCalculator::basisPoints($rate);
            // The ratio that takes the item's amount to the printing basis.
            [$numerator, $denominator] = match (true) {
                $class === InvoiceClass::A && !$item->includesVat, $class === InvoiceClass::B && $item->includesVat => [
                    1,
                    1,
                ],
                $class === InvoiceClass::A => [10000, 10000 + $points],
                default => [10000 + $points, 10000],
            };
            $amounts[$index] = Amounts::ratio($item->amount, $numerator, $denominator);
            $groups[$rate->name][] = [
                'index' => $index,
                'inexact' => ((($item->amount % $denominator) * $numerator) % $denominator) !== 0,
            ];
        }
        $targets = [];
        foreach ($result->totals->vatByRate as $row) {
            $targets[$row->rate->name] = $class === InvoiceClass::A ? $row->base : $row->base + $row->amount;
        }
        foreach ($groups as $rate => $members) {
            $difference =
                ($targets[$rate] ?? 0)
                - array_sum(array_map(static fn (array $member): int => $amounts[$member['index']], $members));
            usort(
                $members,
                static fn (array $left, array $right): int => (
                    [$right['inexact'], $amounts[$right['index']]] <=> [$left['inexact'], $amounts[$left['index']]]
                ),
            );
            for ($step = 0; $difference !== 0; ++$step) {
                $index = $members[$step % count($members)]['index'];
                $cent = $difference > 0 ? 1 : -1;
                if (($amounts[$index] + $cent) < 0) {
                    throw new InvalidArgumentException('The items do not add up to what ARCA authorized.');
                }
                $amounts[$index] += $cent;
                $difference -= $cent;
            }
        }
        ksort($amounts);

        return array_values($amounts);
    }

    /** @return array{string, int} The quantity and the unit price. */
    private static function quantity(PrintedLine $line, int $amount): array
    {
        $gross = $amount + $line->discount;
        if ($line->quantity === null) {
            return ['1', $gross];
        }
        [$whole, $fraction] = array_pad(explode('.', $line->quantity), 2, '');
        $scale = 10 ** strlen($fraction);
        $units = (int) ($whole . $fraction);
        if ($line->unitPrice === null) {
            if ($gross > intdiv(PHP_INT_MAX - $units, 2 * $scale)) {
                throw new InvalidArgumentException('The line amount is too large to derive a unit price.');
            }

            return [$line->quantity, intdiv((2 * $gross * $scale) + $units, 2 * $units)];
        }
        if ($line->unitPrice > intdiv(PHP_INT_MAX - $scale, 2 * $units)) {
            throw new InvalidArgumentException('Quantity × unit price is too large.');
        }
        $product = intdiv((2 * $line->unitPrice * $units) + $scale, 2 * $scale);
        if (abs($product - $gross) > 1) {
            throw new InvalidArgumentException(
                "\"{$line->description}\": quantity × unit price − discount is not the line amount of {$amount} cents"
                . ' (without VAT on class A, with VAT on class B).',
            );
        }

        return [$line->quantity, $line->unitPrice];
    }

    private static function conditionLegend(VatCondition $condition): string
    {
        return match ($condition) {
            VatCondition::RESPONSABLE_INSCRIPTO => 'IVA RESPONSABLE INSCRIPTO',
            VatCondition::EXENTO => 'IVA EXENTO',
            VatCondition::NO_ALCANZADO => 'NO RESPONSABLE IVA',
            VatCondition::MONOTRIBUTISTA => 'RESPONSABLE MONOTRIBUTO',
            VatCondition::MONOTRIBUTISTA_SOCIAL => 'MONOTRIBUTISTA SOCIAL',
            VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO
                => 'MONOTRIBUTO TRABAJADOR INDEPENDIENTE PROMOVIDO',
            VatCondition::CONSUMIDOR_FINAL => 'CONSUMIDOR FINAL',
        };
    }
}
