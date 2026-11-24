<?php

declare(strict_types=1);

namespace Afiphp\Printing;

use InvalidArgumentException;

/** The receiver's name and address. Required on a printed voucher unless the receiver is a final consumer. */
final readonly class ReceiverDetails
{
    public function __construct(
        public ?string $name = null,
        public ?string $address = null,
    ) {
        foreach (['name' => $name, 'address' => $address] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException("Omit the receiver's {$field} instead of leaving it empty.");
            }
        }
    }
}
