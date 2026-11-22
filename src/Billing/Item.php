<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use Afiphp\Internal\Billing\Amounts;

/** Extended amount in integer cents, after quantity and discounts; no hidden multiplication. */
final readonly class Item
{
    private function __construct(
        public int $amount,
        public ?VatRate $vat,
        public bool $includesVat,
    ) {}

    /**
     * What the customer pays for the line. A Responsable Inscripto gives the VAT rate, and the amount
     * includes that VAT; a Monotributista or Exento issuer gives no rate.
     * @param int $amount Integer cents; validated without numeric coercion.
     */
    public static function amount(mixed $amount, ?VatRate $vat = null): self
    {
        return new self(Amounts::validate($amount), $vat, true);
    }

    /**
     * Amount before VAT, for a Responsable Inscripto that prices without VAT.
     * @param int $amount Integer cents; validated without numeric coercion.
     */
    public static function netAmount(mixed $amount, VatRate $vat): self
    {
        return new self(Amounts::validate($amount), $vat, false);
    }
}
