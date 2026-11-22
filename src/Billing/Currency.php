<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use InvalidArgumentException;

/** WSFE currency settings only; no monetary arithmetic or currency conversion. */
final readonly class Currency
{
    private function __construct(
        public string $code,
        public string $exchangeRate,
        public ?bool $paidInSameCurrency,
    ) {}

    public static function pesos(): self
    {
        return new self('ARS', '1', null);
    }

    /** The caller asserts the fiscal rate and payment currency; neither is guessed. */
    public static function dollars(mixed $exchangeRate, bool $paidInDollars): self
    {
        $valid =
            is_string($exchangeRate)
            && preg_match('/^(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,6}))?$/D', $exchangeRate, $parts) === 1;
        if (!$valid || (int) $parts[1] === 0 && (int) ($parts[2] ?? '0') === 0) {
            throw new InvalidArgumentException('Dollar exchange rate requires a positive decimal string '
            . 'with up to 4 integer and 6 decimal digits.');
        }
        $rate = str_contains($exchangeRate, '.') ? rtrim(rtrim($exchangeRate, '0'), '.') : $exchangeRate;

        return new self('USD', $rate, $paidInDollars);
    }
}
