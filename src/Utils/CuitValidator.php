<?php

namespace Afiphp\Utils;

class CuitValidator
{
    public static function validate(string $cuit): bool
    {
        $cuit = str_replace([' ', '-'], '', $cuit);

        if (strlen($cuit) !== 11) {
            return false;
        }

        $accum = 0;

        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $index => $multiplier) {
            $accum += $cuit[$index] * $multiplier;
        }

        $checkDigit = 11 - ($accum % 11);

        if ($checkDigit === 11) {
            $checkDigit = 0;
        }

        if ($checkDigit === 10) {
            $checkDigit = 9;
        }

        return (int)$cuit[10] === $checkDigit;
    }
}
