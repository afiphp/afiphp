<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Billing\Currency;
use Afiphp\Configuration;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\Billing\FiscalGateway;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use Afiphp\Internal\WebServices\Contracts\TicketProvider;
use Afiphp\SalesPoint;
use Afiphp\ServiceStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/** @internal SOAP infrastructure. Application code must use Arca's durable issuance contract. */
final class WsFeV1 extends AbstractWsAfip implements FiscalGateway
{
    private TicketProvider $tickets;
    private ?Ticket $ticket = null;

    public function __construct(
        Configuration $configuration,
        ?SoapTransport $transport = null,
        ?TicketProvider $tickets = null,
    ) {
        parent::__construct($configuration, $transport);
        $this->tickets = $tickets ?? new WsAa($configuration);
    }

    public function execute(string $method, array $params = []): object
    {
        if ($method !== 'FEDummy') {
            try {
                $params['Auth'] = $this->auth();
            } catch (ArcaUnavailable $exception) {
                // The login failed, so this request was never sent.
                throw new ArcaUnavailable($exception->getMessage(), false, $exception);
            }
        }

        return parent::execute($method, $params);
    }

    public function status(): ServiceStatus
    {
        $response = $this->execute('FEDummy');
        $servers = [];
        foreach (['AppServer', 'DbServer', 'AuthServer'] as $field) {
            $value = $response->{$field} ?? null;
            if (!is_string($value)) {
                throw new InvalidResponseException('ARCA returned an incomplete service status.', $response, 'FEDummy');
            }
            $servers[] = $value;
        }

        return new ServiceStatus(...$servers);
    }

    public function salesPoints(): array
    {
        try {
            $response = $this->execute('FEParamGetPtosVenta');
        } catch (WsException $exception) {
            // ARCA answers "no results" (602) instead of an empty list.
            if (Failures::isNotFound($exception)) {
                return [];
            }
            throw $exception;
        }
        $points = [];
        foreach (WsResponse::items($response->PtoVenta ?? null) as $point) {
            $number = WsResponse::field($point, 'Nro');
            $type = WsResponse::field($point, 'EmisionTipo');
            $blocked = WsResponse::field($point, 'Bloqueado');
            $deregistered = WsResponse::field($point, 'FchBaja');
            if (
                !self::isDigits($number, 5)
                || (int) $number < 1
                || !is_string($type)
                || !in_array($blocked, ['S', 'N'], true)
                || !is_string($deregistered) && $deregistered !== null
            ) {
                throw new InvalidResponseException(
                    'ARCA returned an invalid sales point.',
                    $response,
                    'FEParamGetPtosVenta',
                );
            }
            $date = null;
            if ($deregistered !== null && strtoupper(trim($deregistered)) !== 'NULL' && trim($deregistered) !== '') {
                $date = \DateTimeImmutable::createFromFormat(
                    '!Ymd',
                    $deregistered,
                    new \DateTimeZone('America/Argentina/Buenos_Aires'),
                );
                if ($date === false || $date->format('Ymd') !== $deregistered) {
                    throw new InvalidResponseException(
                        'ARCA returned an invalid sales point deregistration date.',
                        $response,
                        'FEParamGetPtosVenta',
                    );
                }
            }
            $points[] = new SalesPoint((int) $number, trim($type), $blocked === 'S', $date);
        }

        return $points;
    }

    public function lastNumber(int $type, int $salesPoint): int
    {
        $response = $this->execute('FECompUltimoAutorizado', ['CbteTipo' => $type, 'PtoVta' => $salesPoint]);
        $number = $response->CbteNro ?? null;
        if (!self::isDigits($number, 8)) {
            throw new InvalidResponseException(
                'ARCA returned an invalid last invoice number.',
                $response,
                'FECompUltimoAutorizado',
            );
        }
        // A number for another series is not a usable sequence.
        foreach (['CbteTipo' => $type, 'PtoVta' => $salesPoint] as $field => $expected) {
            $value = $response->{$field} ?? null;
            if (!self::isDigits($value) || (int) $value !== $expected) {
                throw new InvalidResponseException(
                    'ARCA returned a different or incomplete invoice sequence.',
                    $response,
                    'FECompUltimoAutorizado',
                );
            }
        }

        return (int) $number;
    }

    public function authorize(array $request): object
    {
        return $this->execute('FECAESolicitar', $request);
    }

    public function lookup(int $type, int $salesPoint, int $number): object
    {
        return $this->execute('FECompConsultar', ['FeCompConsReq' => [
            'CbteTipo' => $type,
            'PtoVta' => $salesPoint,
            'CbteNro' => $number,
        ]]);
    }

    public function dollarRate(?DateTimeImmutable $date): string
    {
        $params = ['MonId' => 'DOL'] + ($date === null ? [] : ['FchCotiz' => $date->format('Ymd')]);
        $response = $this->execute('FEParamGetCotizacion', $params);
        try {
            if (($response->MonId ?? null) !== 'DOL') {
                throw new InvalidArgumentException('ARCA returned a rate for another currency.');
            }

            return Currency::dollars($response->MonCotiz ?? null, false)->exchangeRate;
        } catch (InvalidArgumentException $exception) {
            throw new InvalidResponseException(
                'ARCA returned an invalid dollar exchange rate.',
                $response,
                'FEParamGetCotizacion',
                $exception,
            );
        }
    }

    protected function service(): string
    {
        return self::WSFE;
    }

    /** @return array{Token: string, Sign: string, Cuit: string} */
    private function auth(): array
    {
        if ($this->ticket === null || !$this->ticket->isValid()) {
            $this->ticket = $this->tickets->ticket(self::WSFE);
            if (!$this->ticket->isValid()) {
                throw new RuntimeException('The ticket provider returned an expired ticket.');
            }
        }

        return ['Token' => $this->ticket->token, 'Sign' => $this->ticket->sign, 'Cuit' => $this->configuration->cuit];
    }

    private static function isDigits(mixed $value, int $maxLength = PHP_INT_MAX): bool
    {
        return (
            (is_int($value) || is_string($value))
            && preg_match('/^[0-9]+$/D', (string) $value) === 1
            && strlen((string) $value) <= $maxLength
        );
    }
}
