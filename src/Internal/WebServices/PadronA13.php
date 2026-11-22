<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Billing\Receiver;
use Afiphp\Exceptions\InvalidResponseException;
use InvalidArgumentException;

/** @internal ws_sr_padron_a13, getIdPersonaListByDocumento: the CUITs/CUILs registered for a DNI. */
class PadronA13 extends Padron
{
    /** @return list<string> Empty when no person is registered with that DNI. */
    public function cuitsByDni(string $dni): array
    {
        Receiver::validateDni($dni);
        $response = $this->query('getIdPersonaListByDocumento', ['documento' => $dni]);
        $cuits = [];
        foreach (WsResponse::items($response->idPersonaListReturn->idPersona ?? null) as $id) {
            $cuit = is_int($id) || is_string($id) ? (string) $id : '';
            try {
                Receiver::validateCuit($cuit);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidResponseException(
                    'ARCA returned an invalid CUIT for a document.',
                    $response,
                    'getIdPersonaListByDocumento',
                    $exception,
                );
            }
            $cuits[$cuit] = $cuit;
        }

        return array_values($cuits);
    }

    protected function service(): string
    {
        return self::PADRON_A13;
    }

    protected function wsaaService(): string
    {
        return 'ws_sr_padron_a13';
    }
}
