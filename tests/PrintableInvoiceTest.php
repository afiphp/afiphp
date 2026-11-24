<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Printing\IssuerDetails;
use Afiphp\Printing\PrintableInvoice;
use Afiphp\Printing\PrintedLine;
use Afiphp\Printing\ReceiverDetails;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\FakeFiscalGateway;

final class PrintableInvoiceTest extends TestCase
{
    private function client(FakeFiscalGateway $gateway, VatCondition $condition): Arca
    {
        return new Arca(new Configuration('20111111112', $condition, $this->identity(), $this->storage()), $gateway);
    }

    private static function issuer(
        VatCondition $condition = VatCondition::RESPONSABLE_INSCRIPTO,
        bool $cbu = false,
    ): IssuerDetails {
        return new IssuerDetails(
            cuit: '20111111112',
            vatCondition: $condition,
            legalName: 'Ferretería del Sur SRL',
            address: 'Av. Rivadavia 1234, CABA',
            grossIncome: '901-123456-7',
            activitiesStart: new DateTimeImmutable('2019-10-01'),
            paymentToInformedCbu: $cbu,
        );
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    public function testClassALinesAreNetAndCarryTheirRate(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onAuthorize = static function (array $request) use ($gateway): object {
            $gateway->last = 50;
            $approved = FakeFiscalGateway::approved($request);
            $approved->FeDetResp->FECAEDetResponse[0]->Observaciones = (object) ['Obs' => [
                (object) ['Code' => 10217, 'Msg' => 'Observación'],
            ]];

            return $approved;
        };
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::MONOTRIBUTISTA),
            [
                Item::amount(1000, VatRate::STANDARD_21),
                Item::amount(1000, VatRate::STANDARD_21),
                Item::netAmount(500, VatRate::REDUCED_10_5),
                Item::netAmount(300, VatRate::EXEMPT),
            ],
            self::today(),
        );
        $result = $this->client($gateway, VatCondition::RESPONSABLE_INSCRIPTO)->issue($invoice);
        $printable = PrintableInvoice::build(
            $invoice,
            $result,
            self::issuer(cbu: true),
            [
                new PrintedLine('Taladro', quantity: '2', unitPrice: 413),
                new PrintedLine('Mechas'),
                new PrintedLine('Tornillos', quantity: '1.5'),
                new PrintedLine('Libro'),
            ],
            'Contado',
            new ReceiverDetails('Juan Pérez', 'Calle 1'),
        );

        self::assertSame(InvoiceClass::A, $printable->letter);
        self::assertSame('001', $printable->code);
        self::assertSame('FACTURA', $printable->title);
        self::assertSame('00002-00000050', $printable->number);
        self::assertSame(PrintableInvoice::INFORMED_CBU_LEGEND, $printable->letterLegend);
        self::assertSame('IVA RESPONSABLE INSCRIPTO', $printable->issuerConditionLegend);
        self::assertSame('RESPONSABLE MONOTRIBUTO', $printable->receiverConditionLegend);
        // 2000 with VAT at 21% has a base of 1652.89 → 1653: one line takes the rounding cent.
        self::assertSame([827, 826, 500, 300], array_map(static fn ($line): int => $line->amount, $printable->lines));
        self::assertSame(
            [VatRate::STANDARD_21, VatRate::STANDARD_21, VatRate::REDUCED_10_5, VatRate::EXEMPT],
            array_map(static fn ($line) => $line->vat, $printable->lines),
        );
        self::assertSame(['2', 413], [$printable->lines[0]->quantity, $printable->lines[0]->unitPrice]);
        self::assertSame(['1.5', 333], [$printable->lines[2]->quantity, $printable->lines[2]->unitPrice]);
        self::assertSame($result->totals->net, $printable->subtotal);
        self::assertSame(300, $printable->exempt);
        self::assertEquals($result->totals->vatByRate, $printable->vatByRate);
        self::assertNull($printable->transparencyVat);
        self::assertSame(
            [PrintableInvoice::MONOTRIBUTO_CREDIT_LEGEND, 'Observaciones de ARCA: 10217'],
            $printable->legends,
        );
        self::assertSame($result->qr, $printable->qr);
        self::assertSame('71372966559657', $printable->cae);
    }

    public function testClassBLinesIncludeVatAndShowTheTransparencyRegime(): void
    {
        $gateway = new FakeFiscalGateway();
        $invoice = new Invoice(
            2,
            Receiver::finalConsumerWithDni('12345678'),
            [
                Item::netAmount(1, VatRate::STANDARD_21),
                Item::netAmount(1, VatRate::STANDARD_21),
                Item::netAmount(1, VatRate::STANDARD_21),
                Item::amount(12100, VatRate::STANDARD_21),
            ],
            self::today(),
            otherTaxes: [
                new OtherTax(OtherTaxType::INTERNAL, 'Impuestos internos', 10000, '8'),
                new OtherTax(OtherTaxType::GROSS_INCOME_PERCEPTION, 'IIBB', 10000, '3'),
            ],
        );
        $result = $this->client($gateway, VatCondition::RESPONSABLE_INSCRIPTO)->issue($invoice);
        $printable = PrintableInvoice::build(
            $invoice,
            $result,
            self::issuer(cbu: true),
            [new PrintedLine('a'), new PrintedLine('b'), new PrintedLine('c'), new PrintedLine('d', discount: 100)],
            'Contado',
        );
        $rate = $result->totals->vatByRate[0];
        self::assertSame(
            $rate->base + $rate->amount,
            array_sum(array_map(static fn ($line): int => $line->amount, $printable->lines)),
        );
        self::assertSame(12100, $printable->lines[3]->amount);
        self::assertSame(12200, $printable->lines[3]->unitPrice);
        self::assertNull($printable->lines[0]->vat);
        self::assertNull($printable->letterLegend);
        self::assertSame('A CONSUMIDOR FINAL', $printable->receiverConditionLegend);
        self::assertSame(['DNI', '12345678'], [$printable->receiverDocumentLabel, $printable->receiverDocument]);
        self::assertSame([], $printable->vatByRate);
        self::assertSame($result->totals->vat, $printable->transparencyVat);
        self::assertSame(800, $printable->transparencyOtherTaxes);
        self::assertSame($result->totals->total, $printable->total);
        self::assertSame([], $printable->legends);
    }

    public function testClassCPrintsTheItemsAsIssuedAndReprintsFromALookup(): void
    {
        $gateway = new FakeFiscalGateway();
        $invoice = new Invoice(
            2,
            Receiver::finalConsumerWithDni('12345678'),
            [Item::amount(1500), Item::amount(2500)],
            self::today(),
        );
        $client = $this->client($gateway, VatCondition::MONOTRIBUTISTA);
        $result = $client->issue($invoice);
        $lines = [new PrintedLine('Clase de yoga'), new PrintedLine('Clase de pilates', quantity: '2')];
        $issuer = self::issuer(VatCondition::MONOTRIBUTISTA);
        $printable = PrintableInvoice::build($invoice, $result, $issuer, $lines, 'Contado');
        self::assertSame('011', $printable->code);
        self::assertSame([1500, 2500], array_map(static fn ($line): int => $line->amount, $printable->lines));
        self::assertSame(1250, $printable->lines[1]->unitPrice);
        self::assertSame(4000, $printable->subtotal);
        self::assertSame('RESPONSABLE MONOTRIBUTO', $printable->issuerConditionLegend);

        $gateway->onLookup = static fn (): object => FakeFiscalGateway::found($gateway->calls[1][1]);
        $reprint = $client->voucher(InvoiceClass::C, 2, 50);
        self::assertNotNull($reprint);
        self::assertEquals($printable, PrintableInvoice::build($invoice, $reprint, $issuer, $lines, 'Contado'));
    }

    public function testRefusesMissingDataAndAnotherVoucher(): void
    {
        $gateway = new FakeFiscalGateway();
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(1000, VatRate::STANDARD_21)],
            self::today(),
        );
        $result = $this->client($gateway, VatCondition::RESPONSABLE_INSCRIPTO)->issue($invoice);
        $line = [new PrintedLine('Taladro')];
        $receiver = new ReceiverDetails('Empresa SA', 'Calle 1');
        $other = new Invoice(2, $invoice->receiver, [Item::netAmount(1001, VatRate::STANDARD_21)], self::today());
        $otherCuit = new IssuerDetails(
            '20222222223',
            VatCondition::RESPONSABLE_INSCRIPTO,
            'Otra',
            'Calle',
            'Exento',
            null,
        );
        $cases = [
            'name and address' => static fn () => PrintableInvoice::build(
                $invoice,
                $result,
                self::issuer(),
                $line,
                'Contado',
            ),
            'one PrintedLine per Item' => static fn () => PrintableInvoice::build(
                $invoice,
                $result,
                self::issuer(),
                [],
                'Contado',
                $receiver,
            ),
            'sale conditions' => static fn () => PrintableInvoice::build(
                $invoice,
                $result,
                self::issuer(),
                $line,
                ' ',
                $receiver,
            ),
            'not the one ARCA authorized' => static fn () => PrintableInvoice::build(
                $other,
                $result,
                self::issuer(),
                $line,
                'Contado',
                $receiver,
            ),
            "issuer's CUIT" => static fn () => PrintableInvoice::build(
                $invoice,
                $result,
                $otherCuit,
                $line,
                'Contado',
                $receiver,
            ),
            'does not issue class A' => static fn () => PrintableInvoice::build(
                $invoice,
                $result,
                self::issuer(VatCondition::MONOTRIBUTISTA),
                $line,
                'Contado',
                $receiver,
            ),
            'quantity × unit price' => static fn () => PrintableInvoice::build(
                $invoice,
                $result,
                self::issuer(),
                [new PrintedLine('Taladro', quantity: '3', unitPrice: 300)],
                'Contado',
                $receiver,
            ),
        ];
        foreach ($cases as $message => $build) {
            try {
                $build();
                self::fail("Expected a refusal: {$message}.");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testPrintedLinesValidateTheirOwnData(): void
    {
        foreach ([
            static fn () => new PrintedLine(' '),
            static fn () => new PrintedLine('x', quantity: '0'),
            static fn () => new PrintedLine('x', quantity: '1,5'),
            static fn () => new PrintedLine('x', unitPrice: 100),
            static fn () => new PrintedLine('x', discount: -1),
            static fn () => new IssuerDetails('20111111112', VatCondition::RESPONSABLE_INSCRIPTO, 'A', 'B', '', null),
        ] as $index => $create) {
            try {
                $create();
                self::fail("Expected case {$index} to be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
