<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Configuration;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\SoapException;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use Afiphp\Internal\WebServices\Contracts\TicketProvider;
use RuntimeException;

/** @internal ARCA's read-only registry services (sr-padron): shared authentication and "not found" handling. */
abstract class Padron extends AbstractWsAfip
{
    /** ARCA reports a missing person inside the response or as a fault, never with a code. */
    private const NOT_FOUND = [
        'la clave solicitada no existe',
        'no existe persona con ese id',
        'no existe persona con esa clave',
        'no existe persona con ese documento',
        'persona no encontrada',
        'no se encontro informacion para la clave',
    ];

    private TicketProvider $tickets;

    public function __construct(
        Configuration $configuration,
        ?SoapTransport $transport = null,
        ?TicketProvider $tickets = null,
    ) {
        parent::__construct($configuration, $transport);
        $this->tickets = $tickets ?? new WsAa($configuration);
    }

    /** The WSAA service name the certificate must be enabled for. */
    abstract protected function wsaaService(): string;

    /** Null when ARCA answers that the person does not exist. */
    protected function query(string $method, array $params): ?object
    {
        $ticket = $this->tickets->ticket($this->wsaaService());
        if (!$ticket->isValid()) {
            throw new RuntimeException('The ticket provider returned an expired ticket.');
        }
        try {
            return $this->execute($method, [
                'token' => $ticket->token,
                'sign' => $ticket->sign,
                'cuitRepresentada' => (int) $this->configuration->cuit,
            ] + $params);
        } catch (ArcaUnavailable|SoapException $exception) {
            // The registry answers "not found" with a server fault.
            if (self::isNotFound($exception->getMessage())) {
                return null;
            }
            throw $exception;
        }
    }

    protected static function isNotFound(string $message): bool
    {
        $plain = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message) ?: $message);
        foreach (self::NOT_FOUND as $phrase) {
            if (str_contains($plain, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
