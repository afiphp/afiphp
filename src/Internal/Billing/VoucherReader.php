<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Authorization;
use Afiphp\Billing\Currency;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Preview;
use Afiphp\Billing\QrCode;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Billing\VatSubtotal;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Internal\WebServices\WsResponse;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/** @internal Reads a FECompConsultar answer strictly, and derives a full credit note from it. */
final class VoucherReader
{
    private const OPERATION = 'FECompConsultar';

    /**
     * @throws InvalidResponseException When the answer is malformed or about another voucher.
     * @throws UnexpectedValueException When the voucher is valid but uses something afiphp does not model.
     */
    public static function read(
        object $found,
        InvoiceClass $class,
        InvoiceKind $kind,
        int $salesPoint,
        int $number,
    ): FoundVoucher {
        $type = InvoiceMapper::type($class, $kind);
        $integer = static function (string $field) use ($found): int {
            $value = FiscalEvidence::integer($found->{$field} ?? null);
            if ($value === null || strlen($value) > 18) {
                throw self::invalid("ARCA's voucher has an invalid {$field}.", $found);
            }

            return (int) $value;
        };
        $cents = static function (string $field) use ($found): int {
            $value = FiscalEvidence::decimal($found->{$field} ?? null, 2);
            if ($value === null) {
                throw self::invalid("ARCA's voucher has an invalid {$field}.", $found);
            }

            return Amounts::fromDecimal($value);
        };
        foreach ([
            'CbteTipo' => $type,
            'PtoVta' => $salesPoint,
            'CbteDesde' => $number,
            'CbteHasta' => $number,
        ] as $field => $expected) {
            if ($integer($field) !== $expected) {
                throw self::invalid('ARCA returned a different voucher than the one requested.', $found);
            }
        }
        if (($found->Resultado ?? null) !== 'A') {
            throw self::invalid('ARCA returned a voucher that is not authorized.', $found);
        }
        if (($found->EmisionTipo ?? null) !== 'CAE') {
            throw new UnexpectedValueException(
                'The voucher was not authorized with a CAE; afiphp only reads CAE vouchers.',
            );
        }
        $date = self::date($found->CbteFch ?? null, 'CbteFch', $found);
        try {
            $authorization = new Authorization(
                is_string($found->CodAutorizacion ?? null) || is_int($found->CodAutorizacion ?? null)
                    ? (string) $found->CodAutorizacion
                    : '',
                self::date($found->FchVto ?? null, 'FchVto', $found),
            );
        } catch (InvalidArgumentException $exception) {
            throw self::invalid("ARCA's voucher has an invalid authorization code.", $found, $exception);
        }

        $vatByRate = [];
        foreach (WsResponse::items($found->Iva->AlicIva ?? null) as $row) {
            $base = FiscalEvidence::decimal(WsResponse::field($row, 'BaseImp'), 2);
            $amount = FiscalEvidence::decimal(WsResponse::field($row, 'Importe'), 2);
            $id = FiscalEvidence::integer(WsResponse::field($row, 'Id'));
            if ($base === null || $amount === null || $id === null || strlen($id) > 3) {
                throw self::invalid("ARCA's voucher has an invalid VAT row.", $found);
            }
            try {
                $rate = VatRate::fromArcaCode((int) $id);
            } catch (InvalidArgumentException $exception) {
                throw self::invalid("ARCA's voucher has an unknown VAT rate.", $found, $exception);
            }
            if (isset($vatByRate[$rate->name])) {
                throw self::invalid("ARCA's voucher repeats the VAT rate {$rate->name}.", $found);
            }
            $vatByRate[$rate->name] = new VatSubtotal(
                $rate,
                Amounts::fromDecimal($base),
                Amounts::fromDecimal($amount),
            );
        }
        ksort($vatByRate);

        $otherTaxes = [];
        foreach (WsResponse::items($found->Tributos->Tributo ?? null) as $row) {
            $id = FiscalEvidence::integer(WsResponse::field($row, 'Id'));
            $base = FiscalEvidence::decimal(WsResponse::field($row, 'BaseImp'), 2);
            $percentage = FiscalEvidence::decimal(WsResponse::field($row, 'Alic'), 2);
            $amount = FiscalEvidence::decimal(WsResponse::field($row, 'Importe'), 2);
            $description = WsResponse::field($row, 'Desc', '');
            if (
                $id === null
                || strlen($id) > 3
                || $base === null
                || $percentage === null
                || $amount === null
                || !is_string($description)
            ) {
                throw self::invalid("ARCA's voucher has an invalid tax row.", $found);
            }
            $otherTaxes[] = [
                'id' => (int) $id,
                'description' => $description,
                'base' => Amounts::fromDecimal($base),
                'percentage' => $percentage,
                'amount' => Amounts::fromDecimal($amount),
            ];
        }

        $net = $cents('ImpNeto');
        $vat = $cents('ImpIVA');
        $exempt = $cents('ImpOpEx');
        $untaxed = $cents('ImpTotConc');
        $taxes = $cents('ImpTrib');
        $total = $cents('ImpTotal');
        if (($net + $vat + $exempt + $untaxed + $taxes) !== $total) {
            throw self::invalid("ARCA's voucher amounts do not add up to its total.", $found);
        }
        $rows = array_values($vatByRate);
        if (
            $rows !== []
            && (
                array_sum(array_map(static fn (VatSubtotal $row): int => $row->base, $rows)) !== $net
                || array_sum(array_map(static fn (VatSubtotal $row): int => $row->amount, $rows)) !== $vat
            )
            || $rows === [] && $vat !== 0
        ) {
            throw self::invalid("ARCA's voucher VAT rows do not add up to its net and VAT amounts.", $found);
        }
        $totals = new Preview($class, $net, $vat, $exempt, $untaxed, $total, array_values($vatByRate), $taxes);

        $concept = $integer('Concepto');
        $service = null;
        if ($concept === 2 || $concept === 3) {
            $service = [
                'from' => self::date($found->FchServDesde ?? null, 'FchServDesde', $found),
                'to' => self::date($found->FchServHasta ?? null, 'FchServHasta', $found),
                'due' => self::date($found->FchVtoPago ?? null, 'FchVtoPago', $found),
            ];
        } elseif ($concept !== 1) {
            throw self::invalid("ARCA's voucher has an unknown concept.", $found);
        }

        $documentType = $integer('DocTipo');
        $document = FiscalEvidence::integer($found->DocNro ?? null) ?? throw self::invalid(
            "ARCA's voucher has an invalid document number.",
            $found,
        );
        $condition = FiscalEvidence::integer($found->CondicionIVAReceptorId ?? null);
        $receiver = null;
        if ($condition !== null && strlen($condition) <= 3) {
            try {
                $receiver = Receiver::fromArcaDocument(
                    $documentType,
                    $document,
                    VatCondition::fromArcaCode((int) $condition),
                );
            } catch (InvalidArgumentException) {
                // Another document type, such as 99 (unidentified final consumer): not representable.
            }
        }

        $currencyCode = $found->MonId ?? null;
        if (!is_string($currencyCode)) {
            throw self::invalid("ARCA's voucher has no currency.", $found);
        }
        $currency = null;
        if ($currencyCode === 'PES') {
            $currency = Currency::pesos();
        } elseif ($currencyCode === 'DOL' && in_array($found->CanMisMonExt ?? null, ['S', 'N'], true)) {
            $rate = FiscalEvidence::decimal($found->MonCotiz ?? null, 6);
            try {
                $currency = Currency::dollars(
                    $rate === null ? null : rtrim(rtrim($rate, '0'), '.'),
                    $found->CanMisMonExt === 'S',
                );
            } catch (InvalidArgumentException $exception) {
                throw self::invalid("ARCA's voucher has an invalid exchange rate.", $found, $exception);
            }
        }

        try {
            $observations = WsResponse::messages($found->Observaciones->Obs ?? null);
        } catch (UnexpectedValueException $exception) {
            throw self::invalid("ARCA's voucher has malformed observations.", $found, $exception);
        }
        $unsupported = [];
        foreach ([
            'Opcionales' => 'Opcional',
            'Compradores' => 'Comprador',
            'Actividades' => 'Actividad',
        ] as $field => $wrapper) {
            if (WsResponse::items($found->{$field}->{$wrapper} ?? null) !== []) {
                $unsupported[] = $field;
            }
        }

        return new FoundVoucher(
            $class,
            $kind,
            $salesPoint,
            $number,
            $date,
            $concept,
            $documentType,
            $document,
            $receiver,
            $currencyCode,
            $currency,
            $totals,
            $otherTaxes,
            $service,
            $authorization,
            $observations,
            $unsupported,
        );
    }

    public static function result(FoundVoucher $voucher, string $issuerCuit): IssueResult
    {
        $currency = $voucher->currency ?? throw new UnexpectedValueException(
            "The voucher is in currency {$voucher->currencyCode}; afiphp only reads pesos and dollars.",
        );

        return new IssueResult(
            $voucher->class,
            $voucher->kind,
            $voucher->salesPoint,
            $voucher->number,
            $voucher->date,
            $voucher->totals,
            $voucher->authorization,
            $voucher->observations,
            QrCode::url(
                $issuerCuit,
                $voucher->reference(),
                $voucher->totals->total,
                $voucher->authorization->cae,
                $voucher->receiver,
                $currency,
            ),
            $voucher->receiver,
            $currency,
        );
    }

    /**
     * A credit note that mirrors the voucher: the same receiver, currency, exchange rate, amounts by
     * VAT rate and other taxes. Throws InvalidArgumentException when it cannot reproduce them exactly.
     */
    public static function fullCreditNote(
        FoundVoucher $original,
        DateTimeImmutable $date,
        int $salesPoint,
        VatCondition $issuer,
    ): Invoice {
        $fail = static fn (string $reason): InvalidArgumentException => new InvalidArgumentException(
            "Cannot mirror voucher {$original->salesPoint}-{$original->number} in a full credit note: {$reason} "
            . 'Issue the note with explicit items instead.',
        );
        if ($original->unsupported !== []) {
            throw $fail('it carries ' . implode(', ', $original->unsupported) . '.');
        }
        $receiver = $original->receiver ?? throw $fail(
            "its receiver (document type {$original->documentType}) is not supported.",
        );
        $currency = $original->currency ?? throw $fail("its currency {$original->currencyCode} is not supported.");
        $totals = $original->totals;

        $items = [];
        if ($original->class === InvoiceClass::C) {
            if ($totals->vat !== 0 || $totals->exempt !== 0 || $totals->untaxed !== 0 || $totals->vatByRate !== []) {
                throw $fail('a class C voucher must not discriminate VAT, exempt or untaxed amounts.');
            }
            if ($totals->net > 0) {
                $items[] = Item::amount($totals->net);
            }
        } else {
            foreach ($totals->vatByRate as $row) {
                $items[] = self::mirroredItem($row) ?? throw $fail(
                    "its VAT at {$row->rate->name} is not its base times the rate, rounded once.",
                );
            }
            if ($totals->exempt > 0) {
                $items[] = Item::netAmount($totals->exempt, VatRate::EXEMPT);
            }
            if ($totals->untaxed > 0) {
                $items[] = Item::netAmount($totals->untaxed, VatRate::UNTAXED);
            }
        }
        if ($items === []) {
            throw $fail('it has no amounts besides other taxes.');
        }

        $otherTaxes = [];
        foreach ($original->otherTaxes as $row) {
            try {
                $tax = new OtherTax(self::taxType($row['id']), $row['description'], $row['base'], $row['percentage']);
            } catch (InvalidArgumentException $exception) {
                throw $fail("its tax \"{$row['description']}\" is not supported: {$exception->getMessage()}");
            }
            if ($tax->amount !== $row['amount']) {
                throw $fail("its tax \"{$row['description']}\" is not its base times its percentage.");
            }
            $otherTaxes[] = $tax;
        }

        $service = null;
        if ($original->service !== null) {
            $due = $original->service['due'];
            $service = new ServicePeriod(
                $original->service['from'],
                $original->service['to'],
                // ARCA rejects a payment due date before the note's date.
                $due->format('Y-m-d') < $date->format('Y-m-d') ? $date : $due,
                $original->concept === 3,
            );
        }

        $note = new Invoice(
            $salesPoint,
            $receiver,
            $items,
            $date,
            service: $service,
            currency: $currency,
            kind: InvoiceKind::CREDIT_NOTE,
            original: $original->reference(),
            otherTaxes: $otherTaxes,
        );
        $mirrored = InvoiceCalculator::calculate($note, $issuer);
        if ($mirrored != $totals) {
            throw $fail('its amounts cannot be reproduced exactly.');
        }

        return $note;
    }

    /** The single item whose calculation gives exactly this base and VAT, or null when none does. */
    private static function mirroredItem(VatSubtotal $row): ?Item
    {
        $points = InvoiceCalculator::basisPoints($row->rate);
        if (Amounts::ratio($row->base, $points, 10000) === $row->amount) {
            return Item::netAmount($row->base, $row->rate);
        }
        $gross = $row->base + $row->amount;
        if (Amounts::ratio($gross, 10000, 10000 + $points) === $row->base) {
            return Item::amount($gross, $row->rate);
        }

        return null;
    }

    private static function taxType(int $code): OtherTaxType
    {
        foreach (OtherTaxType::cases() as $type) {
            if (InvoiceMapper::taxType($type) === $code) {
                return $type;
            }
        }

        throw new InvalidArgumentException("unknown tax type {$code}.");
    }

    private static function date(mixed $value, string $field, object $found): DateTimeImmutable
    {
        if (!is_string($value) && !is_int($value) || !preg_match('/^[0-9]{8}$/D', (string) $value)) {
            throw self::invalid("ARCA's voucher has an invalid {$field}.", $found);
        }
        $text = (string) $value;
        try {
            return InvoiceMapper::parseDate(
                substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2),
            );
        } catch (InvalidArgumentException $exception) {
            throw self::invalid("ARCA's voucher has an invalid {$field}.", $found, $exception);
        }
    }

    private static function invalid(
        string $message,
        object $found,
        ?Throwable $previous = null,
    ): InvalidResponseException {
        return new InvalidResponseException($message, $found, self::OPERATION, $previous);
    }
}
