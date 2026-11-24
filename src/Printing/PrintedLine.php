<?php

declare(strict_types=1);

namespace Afiphp\Printing;

use InvalidArgumentException;

/**
 * What a printed line says about one Item, which ARCA never receives: its description and, optionally,
 * quantity and unit price. Give one per Item, in the same order.
 */
final readonly class PrintedLine
{
    /**
     * @param string|null $quantity A positive decimal, such as "2" or "1.5". Without it the line is one unit.
     * @param int|null $unitPrice Integer cents, without VAT on class A and with VAT on class B. Derived from
     *     the line amount when omitted; given, it must match quantity × unit price − discount within a cent.
     * @param int $discount Integer cents already taken off the Item's amount (bonificación), in the same basis.
     * @param string|null $unit Such as "kg" or "hs".
     */
    public function __construct(
        public string $description,
        public ?string $quantity = null,
        public ?int $unitPrice = null,
        public int $discount = 0,
        public ?string $code = null,
        public ?string $unit = null,
    ) {
        if (trim($description) === '') {
            throw new InvalidArgumentException('A printed line requires a description.');
        }
        if ($quantity !== null && !preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,6})?$/D', $quantity)) {
            throw new InvalidArgumentException(
                'Quantity must be a decimal string with up to 9 integer and 6 decimal digits.',
            );
        }
        if ($quantity !== null && (float) $quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }
        if ($unitPrice !== null && $quantity === null) {
            throw new InvalidArgumentException('A unit price requires a quantity.');
        }
        if ($unitPrice !== null && $unitPrice < 0 || $discount < 0) {
            throw new InvalidArgumentException('Unit price and discount must not be negative.');
        }
    }
}
