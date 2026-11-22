<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use UnexpectedValueException;

/** @internal SOAP may return one element, many elements, or none. */
final class WsResponse
{
    public static function field(mixed $value, string $name, mixed $default = null): mixed
    {
        if (is_array($value)) {
            return $value[$name] ?? $default;
        }

        return is_object($value) ? $value->{$name} ?? $default : $default;
    }

    /** SOAP servers may return one element, many elements, or no elements. */
    public static function items(mixed $value): array
    {
        return $value === null ? [] : (is_array($value) ? array_values($value) : [$value]);
    }

    public static function messages(mixed $value): array
    {
        return array_map(static function (mixed $issue): string {
            $code = self::field($issue, 'Code');
            if ($code === null || !is_int($code) && !is_string($code)) {
                throw new UnexpectedValueException('An ARCA issue is missing its code.');
            }
            $message = self::field($issue, 'Msg', '');
            if (!is_string($message)) {
                throw new UnexpectedValueException('An ARCA issue has an invalid message.');
            }

            return "({$code}) {$message}";
        }, self::items($value));
    }
}
