<?php

declare(strict_types=1);

namespace Afiphp\Internal\WebServices;

use Afiphp\Billing\Currency;
use Afiphp\Configuration;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Internal\Billing\FiscalGateway;
use Afiphp\Internal\WebServices\Contracts\SoapTransport;
use Afiphp\Internal\WebServices\Contracts\TicketProvider;
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
            $params['Auth'] = $this->auth();
        }

        return parent::execute($method, $params);
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
