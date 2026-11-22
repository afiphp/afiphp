<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Authorization;
use Afiphp\Internal\WebServices\WsResponse;
use UnexpectedValueException;

/** @internal Strict, fail-closed evidence classification. No loose casts of identities. */
final class FiscalEvidence
{
    public static function authorization(object $response, array $request, string $cuit): array
    {
        $header = $response->FeCabResp ?? null;
        $sentHeader = $request['FeCAEReq']['FeCabReq'];
        $sent = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        if (
            !is_object($header)
            || !empty($response->Errors)
            || !self::integerEquals($header->Cuit ?? null, $cuit)
            || !self::integerEquals($header->PtoVta ?? null, $sentHeader['PtoVta'])
            || !self::integerEquals($header->CbteTipo ?? null, $sentHeader['CbteTipo'])
            || !self::integerEquals($header->CantReg ?? null, 1)
            || !in_array($header->Resultado ?? null, ['A', 'R'], true)
        ) {
            return self::unknown('invalid_authorization_header');
        }
        $details = WsResponse::items($response->FeDetResp->FECAEDetResponse ?? null);
        if (count($details) !== 1 || !is_object($details[0])) {
            return self::unknown('invalid_authorization_detail');
        }
        $detail = $details[0];
        foreach (['Concepto', 'DocTipo', 'DocNro', 'CbteDesde', 'CbteHasta', 'CbteFch'] as $field) {
            if (!self::integerEquals($detail->{$field} ?? null, $sent[$field])) {
                return self::unknown('authorization_identity_mismatch');
            }
        }
        if (($detail->Resultado ?? null) !== $header->Resultado) {
            return self::unknown('contradictory_authorization');
        }
        try {
            $observations = WsResponse::messages($detail->Observaciones->Obs ?? null);
            if ($detail->Resultado === 'R') {
                if (
                    !in_array($detail->CAE ?? null, [null, '', '0', 0], true)
                    || !in_array($detail->CAEFchVto ?? null, [null, '', '0', 0], true)
                ) {
                    return self::unknown('rejection_with_authorization');
                }
                return ['status' => 'rejected', 'observations' => $observations];
            }
            $authorization = self::code($detail->CAE ?? null, $detail->CAEFchVto ?? null, $sent['CbteFch']);
            if ($authorization === null) {
                return self::unknown('invalid_authorization_code');
            }

            return [
                'status' => 'authorized',
                'cae' => $authorization->cae,
                'expires' => $authorization->expiresOn->format('Y-m-d'),
                'observations' => $observations,
            ];
        } catch (UnexpectedValueException) {
            return self::unknown('invalid_observations');
        }
    }

    /** Missing or unparsable fields never establish a match. Differences never release the sequence. */
    public static function lookup(object $found, array $request): array
    {
        if (!empty($found->Errors)) {
            return self::unknown('lookup_has_global_errors');
        }
        $sentHeader = $request['FeCAEReq']['FeCabReq'];
        $sent = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        $missing = false;
        foreach ([
            'CbteTipo' => $sentHeader['CbteTipo'],
            'PtoVta' => $sentHeader['PtoVta'],
        ] + $sent as $field => $expected) {
            if (in_array($field, ['Iva', 'CbtesAsoc', 'Tributos', 'PeriodoAsoc'], true)) {
                continue;
            }
            $actual = $found->{$field} ?? null;
            if ($actual === null) {
                $missing = true;
                continue;
            }
            if (in_array($field, ['ImpTotal', 'ImpTotConc', 'ImpNeto', 'ImpOpEx', 'ImpIVA', 'ImpTrib'], true)) {
                $left = self::decimal($expected, 2);
                $right = self::decimal($actual, 2);
            } elseif ($field === 'MonCotiz') {
                $left = self::decimal($expected, 6);
                $right = self::decimal($actual, 6);
            } elseif (in_array($field, ['MonId', 'CanMisMonExt'], true)) {
                $left = $expected;
                $right = is_string($actual) ? $actual : null;
            } else {
                $left = self::integer($expected);
                $right = self::integer($actual);
            }
            if ($left === null || $right === null) {
                $missing = true;
            } elseif ($left !== $right) {
                return ['status' => 'conflict', 'reason' => 'lookup_field_differs:' . $field];
            }
        }
        foreach ([
            'Iva' => ['AlicIva', ['Id' => 0, 'BaseImp' => 2, 'Importe' => 2]],
            'Tributos' => [
                'Tributo',
                ['Id' => 0, 'Desc' => -1, 'BaseImp' => 2, 'Alic' => 2, 'Importe' => 2],
            ],
            'CbtesAsoc' => [
                'CbteAsoc',
                ['Tipo' => 0, 'PtoVta' => 0, 'Nro' => 0, 'Cuit' => 0, 'CbteFch' => 0],
            ],
        ] as $field => [$wrapper, $fields]) {
            $expectedRows = $sent[$field][$wrapper] ?? [];
            $actualRows = WsResponse::items($found->{$field}->{$wrapper} ?? null);
            $left = self::rows($expectedRows, $fields);
            $right = self::rows($actualRows, $fields);
            if ($left === null || $right === null) {
                $missing = true;
            } elseif ($left !== $right) {
                // Omitted expected rows are incomplete, not proof of a different invoice.
                if ($expectedRows !== [] && $actualRows === []) {
                    $missing = true;
                } else {
                    return ['status' => 'conflict', 'reason' => 'lookup_details_differ:' . $field];
                }
            }
        }
        $period = self::period($found->PeriodoAsoc ?? null);
        if (!isset($sent['PeriodoAsoc'])) {
            if ($period !== null) {
                return ['status' => 'conflict', 'reason' => 'lookup_has_unsupported_fields:PeriodoAsoc'];
            }
        } elseif ($period === null) {
            $missing = true;
        } elseif ($period !== $sent['PeriodoAsoc']) {
            return ['status' => 'conflict', 'reason' => 'lookup_field_differs:PeriodoAsoc'];
        }
        // A nonzero tribute or unsupported fiscal extension cannot be ignored.
        foreach ([
            'Opcionales' => 'Opcional',
            'Compradores' => 'Comprador',
            'Actividades' => 'Actividad',
        ] as $field => $wrapper) {
            if (WsResponse::items($found->{$field}->{$wrapper} ?? null) !== []) {
                return [
                    'status' => 'conflict',
                    'reason' => 'lookup_has_unsupported_fields:' . $field,
                ];
            }
        }
        $authorization = self::code($found->CodAutorizacion ?? null, $found->FchVto ?? null, $sent['CbteFch']);
        if (
            $missing
            || ($found->Resultado ?? null) !== 'A'
            || $authorization === null
            || !in_array($found->EmisionTipo ?? null, ['CAE'], true)
        ) {
            return self::unknown('incomplete_lookup');
        }

        return [
            'status' => 'authorized',
            'cae' => $authorization->cae,
            'expires' => $authorization->expiresOn->format('Y-m-d'),
            'reconciled' => true,
        ];
    }

    /** @return array{FchDesde: string, FchHasta: string}|null Null when absent, empty or malformed. */
    private static function period(mixed $value): ?array
    {
        $from = WsResponse::field($value, 'FchDesde');
        $to = WsResponse::field($value, 'FchHasta');
        foreach ([$from, $to] as $date) {
            if (!is_string($date) || !preg_match('/^[0-9]{8}$/D', $date)) {
                return null;
            }
        }

        return ['FchDesde' => $from, 'FchHasta' => $to];
    }

    private static function unknown(string $reason): array
    {
        return ['status' => 'pending', 'reason' => $reason];
    }

    private static function code(mixed $cae, mixed $expiry, mixed $invoiceDate): ?Authorization
    {
        if (
            !is_string($cae) && !is_int($cae)
            || !is_string($expiry) && !is_int($expiry)
            || !preg_match('/^[0-9]{8}$/D', (string) $expiry)
            || (string) $expiry < (string) $invoiceDate
        ) {
            return null;
        }
        try {
            $date = (string) $expiry;
            return new Authorization(
                (string) $cae,
                InvoiceMapper::parseDate(substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2)),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function integer(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value) || !preg_match('/^[0-9]+$/D', (string) $value)) {
            return null;
        }
        return ltrim((string) $value, '0') ?: '0';
    }

    private static function integerEquals(mixed $left, mixed $right): bool
    {
        $normalized = self::integer($left);
        return $normalized !== null && $normalized === self::integer($right);
    }

    private static function decimal(mixed $value, int $scale): ?string
    {
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                return null;
            }
            $text = number_format($value, $scale, '.', '');
            // Only normalize the binary representation noise of a SOAP xsd:double.
            if (abs((float) $text - $value) > max(1e-9, abs($value) * PHP_FLOAT_EPSILON * 2)) {
                return null;
            }
            $value = $text;
        }
        if (
            !is_string($value) && !is_int($value)
            || !preg_match('/^([0-9]{1,13})(?:\.([0-9]{1,' . $scale . '}))?$/D', (string) $value, $parts)
        ) {
            return null;
        }
        return (ltrim($parts[1], '0') ?: '0') . '.' . str_pad($parts[2] ?? '', $scale, '0');
    }

    private static function rows(array $rows, array $fields): ?array
    {
        $result = [];
        foreach ($rows as $row) {
            $normalized = [];
            foreach ($fields as $field => $scale) {
                $value = WsResponse::field($row, $field);
                $value = match ($scale) {
                    -1 => is_string($value) ? $value : null,
                    0 => self::integer($value),
                    default => self::decimal($value, $scale),
                };
                if ($value === null) {
                    return null;
                }
                $normalized[$field] = $value;
            }
            $result[] = json_encode($normalized, JSON_THROW_ON_ERROR);
        }
        sort($result);
        return $result;
    }
}
