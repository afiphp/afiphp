<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Billing\Receiver;
use Afiphp\Billing\Taxpayer;
use Afiphp\Billing\VatCondition;

/** @internal ws_sr_constancia_inscripcion (Padrón A5), getPersona_v2. Read-only. */
class PadronA5 extends Padron
{
    /** Active VAT registrations (idImpuesto) that decide a receiver's condition. */
    private const VAT_TAXES = [
        30 => VatCondition::RESPONSABLE_INSCRIPTO,
        20 => VatCondition::MONOTRIBUTISTA,
        32 => VatCondition::EXENTO,
        34 => VatCondition::NO_ALCANZADO,
    ];

    public function taxpayer(string $cuit): ?Taxpayer
    {
        Receiver::validateCuit($cuit);
        $response = $this->query('getPersona_v2', ['idPersona' => (int) $cuit]);
        $person = $response->personaReturn ?? null;
        if (!is_object($person)) {
            return null;
        }
        $errors = array_values(array_filter(
            WsResponse::items($person->errorConstancia->error ?? null),
            static fn (mixed $error): bool => is_string($error) && trim($error) !== '',
        ));
        foreach ($errors as $error) {
            if (self::isNotFound($error)) {
                return null;
            }
        }
        $general = $person->datosGenerales ?? null;
        $address = $general->domicilioFiscal ?? null;

        return new Taxpayer(
            $cuit,
            self::name($general),
            self::text($general->tipoPersona ?? null),
            $errors === [] ? self::condition($person) : null,
            self::text($address->direccion ?? null),
            self::text($address->localidad ?? null),
            self::text($address->codPostal ?? null),
            self::text($address->descripcionProvincia ?? null),
            $errors,
        );
    }

    protected function service(): string
    {
        return self::PADRON_A5;
    }

    protected function wsaaService(): string
    {
        return 'ws_sr_constancia_inscripcion';
    }

    /** One active VAT registration decides; none is a final consumer; several is nobody's call. */
    private static function condition(object $person): ?VatCondition
    {
        $conditions = [];
        foreach (['datosRegimenGeneral', 'datosMonotributo'] as $regime) {
            foreach (WsResponse::items($person->{$regime}->impuesto ?? null) as $tax) {
                $condition = self::VAT_TAXES[(int) WsResponse::field($tax, 'idImpuesto')] ?? null;
                $state = WsResponse::field($tax, 'estadoImpuesto');
                if ($condition !== null && ($state === null || strtoupper(trim((string) $state)) === 'AC')) {
                    $conditions[$condition->name] = $condition;
                }
            }
        }

        return match (count($conditions)) {
            0 => VatCondition::CONSUMIDOR_FINAL,
            1 => reset($conditions),
            default => null,
        };
    }

    private static function name(mixed $general): ?string
    {
        $company = self::text($general->razonSocial ?? null);
        if ($company !== null) {
            return $company;
        }
        $surname = self::text($general->apellido ?? null) ?? '';
        $name = trim($surname . ' ' . (self::text($general->nombre ?? null) ?? ''));

        return $name === '' ? null : $name;
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) || is_int($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
