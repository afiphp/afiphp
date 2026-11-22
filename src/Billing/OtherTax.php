<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use Afiphp\Internal\Billing\Amounts;
use InvalidArgumentException;

/** A non-VAT tax. Base and derived amount are in integer cents. */
final readonly class OtherTax
{
    public int $amount;
    public int $base;
    public string $percentage;

    /** @param int $base Integer cents; validated without numeric coercion. */
    public function __construct(
        public OtherTaxType $type,
        public string $description,
        mixed $base,
        mixed $percentage,
    ) {
        $this->base = Amounts::validate($base);
        $isText = preg_match('//u', $description) === 1 && preg_match('/[\x00-\x1f\x7f]/', $description) === 0;
        if (!$isText || trim($description) === '' || strlen($description) > 80) {
            throw new InvalidArgumentException('Tax description requires non-empty UTF-8 text of at most 80 bytes.');
        }
        $pattern = '/^(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?$/D';
        if (!is_string($percentage) || !preg_match($pattern, $percentage, $parts)) {
            throw new InvalidArgumentException('Tax percentage requires a decimal string from 0 through 999.99, '
            . 'with at most two decimals.');
        }
        $hundredths = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
        $this->percentage = sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
        $this->amount = Amounts::ratio($this->base, $hundredths, 10000);
    }
}
