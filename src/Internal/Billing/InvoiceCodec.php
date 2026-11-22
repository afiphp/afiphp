<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\AssociatedPeriod;
use Afiphp\Billing\Currency;
use Afiphp\Billing\DocumentType;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @internal Persisted form of an Invoice intent, so a note can be derived from its original.
 * Enums are stored as WSFE codes or fixed tokens, never as public case names.
 * Decoding goes through the public constructors, so every invariant is checked again.
 */
final class InvoiceCodec
{
    private const VAT_TOKENS = [
        'ZERO' => '0',
        'REDUCED_2_5' => '2.5',
        'REDUCED_5' => '5',
        'REDUCED_10_5' => '10.5',
        'STANDARD_21' => '21',
        'INCREASED_27' => '27',
        'EXEMPT' => 'exempt',
        'UNTAXED' => 'untaxed',
    ];

    public static function encode(Invoice $invoice): array
    {
        $receiver = $invoice->receiver;

        return [
            'salesPoint' => $invoice->salesPoint,
            'receiver' => [
                'condition' => InvoiceMapper::condition($receiver->vatCondition),
                'documentType' => InvoiceMapper::documentType($receiver->documentType),
                'document' => $receiver->document,
            ],
            'items' => array_map(static fn (Item $item): array => [
                'amount' => $item->amount,
                'vat' => $item->vat === null ? null : self::VAT_TOKENS[$item->vat->name],
                'includesVat' => $item->includesVat,
            ], $invoice->items),
            'date' => $invoice->date->format('Y-m-d'),
            'service' => $invoice->service === null
                ? null
                : [
                    'from' => $invoice->service->from->format('Y-m-d'),
                    'to' => $invoice->service->to->format('Y-m-d'),
                    'paymentDue' => $invoice->service->paymentDue->format('Y-m-d'),
                    'includesProducts' => $invoice->service->includesProducts,
                ],
            'currency' => [
                'code' => $invoice->currency->code,
                'exchangeRate' => $invoice->currency->exchangeRate,
                'paidInSameCurrency' => $invoice->currency->paidInSameCurrency,
            ],
            'kind' => $invoice->kind->value,
            'original' => $invoice->original === null
                ? null
                : [
                    'class' => $invoice->original->class->value,
                    'kind' => $invoice->original->kind->value,
                    'salesPoint' => $invoice->original->salesPoint,
                    'number' => $invoice->original->number,
                    'date' => $invoice->original->date->format('Y-m-d'),
                ],
            'otherTaxes' => array_map(static fn (OtherTax $tax): array => [
                'type' => InvoiceMapper::taxType($tax->type),
                'description' => $tax->description,
                'base' => $tax->base,
                'percentage' => $tax->percentage,
            ], $invoice->otherTaxes),
            'period' => $invoice->period === null
                ? null
                : [
                    'from' => $invoice->period->from->format('Y-m-d'),
                    'to' => $invoice->period->to->format('Y-m-d'),
                ],
        ];
    }

    public static function decode(array $data): Invoice
    {
        $date = static fn (mixed $value): DateTimeImmutable => InvoiceMapper::parseDate(self::string($value));
        $service = $data['service'] ?? null;
        $original = $data['original'] ?? null;
        $period = $data['period'] ?? null;
        $currency = $data['currency'] ?? null;

        return new Invoice(
            $data['salesPoint'] ?? 0,
            self::receiver($data['receiver'] ?? null),
            array_map(self::item(...), self::list($data['items'] ?? null)),
            $date($data['date'] ?? null),
            $service === null
                ? null
                : new ServicePeriod(
                    $date($service['from'] ?? null),
                    $date($service['to'] ?? null),
                    $date($service['paymentDue'] ?? null),
                    ($service['includesProducts'] ?? null) === true,
                ),
            match ($currency['code'] ?? null) {
                'ARS' => Currency::pesos(),
                'USD' => Currency::dollars(
                    $currency['exchangeRate'] ?? null,
                    ($currency['paidInSameCurrency'] ?? null) === true,
                ),
                default => throw new InvalidArgumentException('Unknown stored currency.'),
            },
            InvoiceKind::from(self::string($data['kind'] ?? null)),
            $original === null
                ? null
                : new InvoiceReference(
                    InvoiceClass::from(self::string($original['class'] ?? null)),
                    $original['salesPoint'] ?? 0,
                    $original['number'] ?? 0,
                    $date($original['date'] ?? null),
                    InvoiceKind::from(self::string($original['kind'] ?? null)),
                ),
            array_map(
                static fn (mixed $tax): OtherTax => new OtherTax(
                    self::fromCode(OtherTaxType::cases(), InvoiceMapper::taxType(...), $tax['type'] ?? null),
                    self::string($tax['description'] ?? null),
                    $tax['base'] ?? null,
                    $tax['percentage'] ?? null,
                ),
                self::list($data['otherTaxes'] ?? null),
            ),
            $period === null
                ? null
                : new AssociatedPeriod($date($period['from'] ?? null), $date($period['to'] ?? null)),
        );
    }

    private static function receiver(mixed $data): Receiver
    {
        $condition = self::fromCode(VatCondition::cases(), InvoiceMapper::condition(...), $data['condition'] ?? null);
        $type = self::fromCode(DocumentType::cases(), InvoiceMapper::documentType(...), $data['documentType'] ?? null);
        $document = self::string($data['document'] ?? null);

        return match (true) {
            $type === DocumentType::DNI => Receiver::finalConsumerWithDni($document),
            $type === DocumentType::CUIL => Receiver::finalConsumerWithCuil($document),
            $condition === VatCondition::CONSUMIDOR_FINAL => Receiver::finalConsumerWithCuit($document),
            default => Receiver::business($document, $condition),
        };
    }

    private static function item(mixed $data): Item
    {
        $token = $data['vat'] ?? null;
        if ($token === null) {
            return Item::amount($data['amount'] ?? null);
        }
        $name = array_search($token, self::VAT_TOKENS, true);
        if ($name === false) {
            throw new InvalidArgumentException('Unknown stored VAT rate.');
        }
        $rate = constant(VatRate::class . '::' . $name);

        return ($data['includesVat'] ?? null) === true
            ? Item::amount($data['amount'] ?? null, $rate)
            : Item::netAmount($data['amount'] ?? null, $rate);
    }

    /**
     * @template T of \UnitEnum
     * @param list<T> $cases
     * @param callable(T): int $code
     * @return T
     */
    private static function fromCode(array $cases, callable $code, mixed $value): \UnitEnum
    {
        foreach ($cases as $case) {
            if ($code($case) === $value) {
                return $case;
            }
        }
        throw new InvalidArgumentException('Unknown stored fiscal code.');
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid stored invoice intent.');
        }

        return $value;
    }

    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Invalid stored invoice intent.');
        }

        return $value;
    }
}
