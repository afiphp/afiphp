<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\Currency;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Outcome;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Exceptions\ServiceUnavailable;
use Afiphp\Exceptions\SoapException;
use Afiphp\Internal\WebServices\WsFeV1;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFiscalGateway;
use Tests\Support\FakeTicketProvider;
use Tests\Support\FakeTransport;

/** Public contract -> adapter -> authentication -> protocol -> durable evidence. */
final class ArcaProtocolTest extends TestCase
{
    #[DataProvider('scenarios')]
    public function testCompletePublicFlowPreservesItsFiscalHeader(string $scenario, int $type): void
    {
        $condition = $scenario === 'C' ? VatCondition::MONOTRIBUTISTA : VatCondition::RESPONSABLE_INSCRIPTO;
        $receiver = $scenario === 'B'
            ? Receiver::finalConsumerWithDni('12345678')
            : Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO);
        $date = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $invoice = new Invoice(
            2,
            $receiver,
            [$scenario === 'C' ? Item::amount(15000) : Item::amount(18150, VatRate::STANDARD_21)],
            $date,
            service: $scenario === 'note' ? new ServicePeriod($date, $date, $date) : null,
            currency: $scenario === 'note' ? Currency::dollars('1234.567890', false) : Currency::pesos(),
            kind: $scenario === 'note' ? InvoiceKind::CREDIT_NOTE : InvoiceKind::INVOICE,
            original: $scenario === 'note' ? new InvoiceReference(InvoiceClass::A, 3, 7, $date) : null,
            otherTaxes: $scenario === 'note' ? [new OtherTax(OtherTaxType::PROVINCIAL, 'IIBB', 15000, '5.2')] : [],
        );
        $transport = new FakeTransport(static function (string $method, array $params) use ($type): object {
            self::assertSame(['Token' => 'token', 'Sign' => 'sign', 'Cuit' => '20111111112'], $params['Auth']);
            if ($method === 'FECompUltimoAutorizado') {
                self::assertSame($type, $params['CbteTipo']);
                return (object) ['FECompUltimoAutorizadoResult' => (object) [
                    'CbteNro' => 49,
                    'PtoVta' => 2,
                    'CbteTipo' => $type,
                ]];
            }
            self::assertSame('FECAESolicitar', $method);
            return (object) ['FECAESolicitarResult' => FakeFiscalGateway::approved($params)];
        });
        $client = $this->client($transport, $condition);
        $result = $client->issue($invoice, 'sale');
        self::assertSame(Outcome::AUTHORIZED, $result->outcome);
        $detail = $transport->calls[1][1]['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        self::assertSame(
            $scenario === 'C' ? '150.00' : ($scenario === 'note' ? '189.30' : '181.50'),
            $detail['ImpTotal'],
        );
        if ($scenario === 'note') {
            self::assertSame(2, $detail['Concepto']);
            self::assertSame('1234.56789', $detail['MonCotiz']);
            self::assertSame('DOL', $detail['MonId']);
            self::assertSame('N', $detail['CanMisMonExt']);
            self::assertSame('7.80', $detail['ImpTrib']);
            self::assertSame(7, $detail['CbtesAsoc']['CbteAsoc'][0]['Nro']);
        }
        if ($scenario === 'C') {
            self::assertArrayNotHasKey('Iva', $detail);
        }
        $json = file_get_contents(glob($this->directory . '/storage/issuance/*.json')[0]);
        self::assertStringNotContainsString('"Token"', $json);
        self::assertStringNotContainsString('"Sign"', $json);
        $saved = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $record = $saved['records'][hash('sha256', 'sale')];
        self::assertSame('authorized', $record['status']);
        self::assertCount(2, $record['history']);
    }

    public static function scenarios(): array
    {
        return [['A', 1], ['B', 6], ['C', 11], ['note', 3]];
    }

    public function testUnreachableArcaBeforeReservingLeavesNothingPending(): void
    {
        $down = true;
        $transport = new FakeTransport(static function (string $method, array $params) use (&$down): object {
            if ($down) {
                return new \SoapFault('HTTP', 'Could not connect to host');
            }
            if ($method === 'FECompUltimoAutorizado') {
                return (object) ['CbteNro' => 49, 'PtoVta' => 2, 'CbteTipo' => 1];
            }
            return (object) ['FECAESolicitarResult' => FakeFiscalGateway::approved($params)];
        });
        $client = $this->client($transport);
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(15000, VatRate::STANDARD_21)],
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
        );
        try {
            $client->issue($invoice, 'sale');
            self::fail('Expected ARCA to be unavailable.');
        } catch (ServiceUnavailable) {
        }
        self::assertSame(['FECompUltimoAutorizado'], array_column($transport->calls, 0));
        $down = false;
        $result = $client->issue($invoice, 'sale');
        self::assertSame(Outcome::AUTHORIZED, $result->outcome);
        self::assertSame(50, $result->number);
    }

    public function testLastNumberReadsTheSeriesOfTheClassAndKind(): void
    {
        $transport = new FakeTransport(static fn (string $method, array $params): object => (object) [
            'CbteNro' => 7,
            'PtoVta' => $params['PtoVta'],
            'CbteTipo' => $params['CbteTipo'],
        ]);
        self::assertSame(7, $this->client($transport)->lastNumber(InvoiceClass::C, InvoiceKind::CREDIT_NOTE, 3));
        self::assertSame(13, $transport->calls[0][1]['CbteTipo']);
        self::assertSame(3, $transport->calls[0][1]['PtoVta']);
    }

    public function testRealError602IsConsultationEvidenceNotAnImplicitRejectionOrAutomaticRetry(): void
    {
        $attempt = 0;
        $transport = new FakeTransport(static function (string $method, array $params) use (&$attempt): object {
            if ($method === 'FECompUltimoAutorizado') {
                return (object) ['CbteNro' => 49, 'PtoVta' => 2, 'CbteTipo' => 1];
            }
            if ($method === 'FECompConsultar') {
                return (object) ['FECompConsultarResult' => (object) ['Errors' => (object) ['Err' => [(object) [
                    'Code' => 602,
                    'Msg' => 'Sin resultados',
                ]]]]];
            }
            if (++$attempt === 1) {
                throw new SoapException(new \SoapFault('HTTP', 'Timeout'));
            }
            return (object) ['FECAESolicitarResult' => FakeFiscalGateway::approved($params)];
        });
        $client = $this->client($transport);
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(15000, VatRate::STANDARD_21)],
            new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
        );
        self::assertSame(Outcome::INDETERMINATE, $client->issue($invoice, 'sale')->outcome);
        self::assertSame(Outcome::INDETERMINATE, $client->recover('sale')->outcome);
        self::assertSame(1, $attempt);
        self::assertSame(Outcome::AUTHORIZED, $client->retry('sale')->outcome);
        self::assertSame(2, $attempt);
        self::assertSame(
            [
                'FECompUltimoAutorizado',
                'FECAESolicitar',
                'FECompConsultar',
                'FECompConsultar',
                'FECompUltimoAutorizado',
                'FECAESolicitar',
            ],
            array_column($transport->calls, 0),
        );
        self::assertSame($transport->calls[1][1]['FeCAEReq'], $transport->calls[5][1]['FeCAEReq']);
    }

    private function client(
        FakeTransport $transport,
        VatCondition $condition = VatCondition::RESPONSABLE_INSCRIPTO,
    ): Arca {
        $configuration = new Configuration('20111111112', $condition, $this->identity(), $this->storage());
        return new Arca($configuration, new WsFeV1($configuration, $transport, new FakeTicketProvider()));
    }
}
