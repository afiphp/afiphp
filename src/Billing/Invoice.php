<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;
use InvalidArgumentException;

/** Intent only: no SOAP fields, authorization state, mutable dates, or assigned number. */
final readonly class Invoice
{
    /** @var non-empty-list<Item> */
    public array $items;
    public Currency $currency;
    /** @var list<OtherTax> */
    public array $otherTaxes;

    /**
     * @param array<array-key, mixed> $items Validated as a non-empty list of Item instances.
     * @param array<array-key, mixed> $otherTaxes Validated as a list of OtherTax instances.
     */
    public function __construct(
        public int $salesPoint,
        public Receiver $receiver,
        array $items,
        public DateTimeImmutable $date,
        public ?ServicePeriod $service = null,
        ?Currency $currency = null,
        public InvoiceKind $kind = InvoiceKind::INVOICE,
        public ?InvoiceReference $original = null,
        array $otherTaxes = [],
        public ?AssociatedPeriod $period = null,
    ) {
        if ($salesPoint < 1 || $salesPoint > 99999) {
            throw new InvalidArgumentException('Sales point must be from 1 through 99999.');
        }
        if ($items === [] || !self::isListOf($items, Item::class)) {
            throw new InvalidArgumentException('An invoice requires a non-empty list of Item instances.');
        }
        if (!self::isListOf($otherTaxes, OtherTax::class)) {
            throw new InvalidArgumentException('Other taxes must be a list of OtherTax instances.');
        }
        if ($kind === InvoiceKind::INVOICE && ($original !== null || $period !== null)) {
            throw new InvalidArgumentException('An invoice must not reference an original voucher or period.');
        }
        if ($kind !== InvoiceKind::INVOICE && ($original === null) === ($period === null)) {
            throw new InvalidArgumentException(
                'A credit/debit note requires either an original voucher or an associated period, not both.',
            );
        }
        // WSFE rule 10040: only a debit note may adjust a credit note.
        if ($kind === InvoiceKind::CREDIT_NOTE && $original?->kind === InvoiceKind::CREDIT_NOTE) {
            throw new InvalidArgumentException('A credit note must adjust an invoice or a debit note.');
        }
        if ($service !== null && $service->paymentDue->format('Y-m-d') < $date->format('Y-m-d')) {
            throw new InvalidArgumentException('Payment due date must not be before invoice date.');
        }
        if ($original !== null && $original->date->format('Y-m-d') > $date->format('Y-m-d')) {
            throw new InvalidArgumentException('A note must not predate its original invoice.');
        }
        $this->items = $items;
        $this->currency = $currency ?? Currency::pesos();
        $this->otherTaxes = $otherTaxes;
    }

    /** @param class-string $class */
    private static function isListOf(array $values, string $class): bool
    {
        if (!array_is_list($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                return false;
            }
        }

        return true;
    }
}
