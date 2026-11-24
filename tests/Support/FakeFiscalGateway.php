<?php

declare(strict_types=1);

namespace Tests\Support;

use Afiphp\Internal\Billing\FiscalGateway;

final class FakeFiscalGateway implements FiscalGateway
{
    public array $calls = [];
    public int $last = 49;
    public ?\Closure $onAuthorize = null;
    public ?\Closure $onLookup = null;
    /** Returns the sales points, or throws. */
    public ?\Closure $onSalesPoints = null;
    public ?\Afiphp\ServiceStatus $status = null;

    public function status(): \Afiphp\ServiceStatus
    {
        $this->calls[] = ['status'];
        return $this->status ?? new \Afiphp\ServiceStatus('OK', 'OK', 'OK');
    }

    public function salesPoints(): array
    {
        $this->calls[] = ['salesPoints'];
        return $this->onSalesPoints === null ? [] : ($this->onSalesPoints)();
    }

    public function lastNumber(int $type, int $salesPoint): int
    {
        $this->calls[] = ['last', $type, $salesPoint];
        return $this->last;
    }

    public function authorize(array $request): object
    {
        $this->calls[] = ['authorize', $request];
        if ($this->onAuthorize !== null) {
            return ($this->onAuthorize)($request);
        }
        $this->last = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0]['CbteDesde'];
        return self::approved($request);
    }

    public function lookup(int $type, int $salesPoint, int $number): object
    {
        $this->calls[] = ['lookup', $type, $salesPoint, $number];
        return $this->onLookup === null ? (object) [] : ($this->onLookup)($type, $salesPoint, $number);
    }

    public function dollarRate(?\DateTimeImmutable $date): string
    {
        $this->calls[] = ['dollarRate', $date?->format('Y-m-d')];
        return '1000';
    }

    public static function approved(array $request): object
    {
        $header = $request['FeCAEReq']['FeCabReq'];
        $detail = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        return (object) [
            'FeCabResp' => (object) ($header + ['Cuit' => '20111111112', 'Resultado' => 'A']),
            'FeDetResp' => (object) ['FECAEDetResponse' => [
                (object) (
                    $detail
                    + [
                        'Resultado' => 'A',
                        'CAE' => '71372966559657',
                        'CAEFchVto' => self::expiry($detail['CbteFch']),
                    ]
                ),
            ]],
        ];
    }

    public static function found(array $request): object
    {
        $header = $request['FeCAEReq']['FeCabReq'];
        $detail = $request['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        return json_decode(
            json_encode(
                $detail
                + [
                    'CbteTipo' => $header['CbteTipo'],
                    'PtoVta' => $header['PtoVta'],
                    'Resultado' => 'A',
                    'CodAutorizacion' => '71372966559657',
                    'FchVto' => self::expiry($detail['CbteFch']),
                    'EmisionTipo' => 'CAE',
                ],
                JSON_THROW_ON_ERROR,
            ),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private static function expiry(string $date): string
    {
        return \DateTimeImmutable::createFromFormat('!Ymd', $date)->modify('+10 days')->format('Ymd');
    }
}
