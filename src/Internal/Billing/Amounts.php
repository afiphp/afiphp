<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use InvalidArgumentException;

/** @internal Exact WSFE amounts in integer cents, not a general-purpose money API. */
final class Amounts
{
    public const MAX_CENTS = 999_999_999_999_999;

    public static function validate(mixed $cents): int
    {
        if (!is_int($cents) || $cents < 0 || $cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Amount requires integer cents from 0 through ' . self::MAX_CENTS . '.');
        }

        return $cents;
    }

    public static function decimal(int $cents): string
    {
        self::validate($cents);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** Strict inverse of decimal(): exactly two decimals, no coercion. */
    public static function fromDecimal(string $decimal): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,12})\.([0-9]{2})$/D', $decimal, $parts)) {
            throw new InvalidArgumentException('Expected a WSFE decimal amount with two decimals.');
        }

        return self::validate(((int) $parts[1] * 100) + (int) $parts[2]);
    }

    public static function add(int $left, int $right): int
    {
        self::validate($left);
        self::validate($right);

        return self::validate($left + $right);
    }

    /** Integer-only Round Half Up (1.005 -> 1.01); split first to avoid 64-bit multiplication overflow. */
    public static function ratio(int $cents, int $numerator, int $denominator): int
    {
        self::validate($cents);
        if ($numerator < 0 || $numerator > 99999 || $denominator < 1 || $denominator > 20000) {
            throw new InvalidArgumentException('Invalid monetary ratio.');
        }
        $divided = intdiv($cents, $denominator);
        if ($numerator !== 0 && $divided > intdiv(self::MAX_CENTS, $numerator)) {
            throw new InvalidArgumentException('Monetary ratio exceeds the fiscal amount limit.');
        }
        $whole = $divided * $numerator;
        $rest = ($cents % $denominator) * $numerator;
        $quotient = $whole + intdiv($rest, $denominator);
        $remainder = $rest % $denominator;
        if (($remainder * 2) >= $denominator) {
            ++$quotient;
        }

        return self::validate($quotient);
    }
}
