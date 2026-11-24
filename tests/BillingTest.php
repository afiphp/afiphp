<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Billing\AssociatedPeriod;
use Afiphp\Billing\Currency;
use Afiphp\Billing\DocumentType;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\OtherTaxType;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Internal\Billing\Amounts;
use Afiphp\Internal\Billing\InvoiceCalculator;
use Afiphp\Internal\Billing\InvoiceMapper;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingTest extends TestCase
{
    #[DataProvider('invalidAmounts')]
    public function testItemsAndTaxesRejectNonIntegerOrOutOfRangeAmounts(mixed $value): void
    {
        foreach ([
            static fn () => Item::netAmount($value, VatRate::STANDARD_21),
            static fn () => Item::amount($value, VatRate::STANDARD_21),
            static fn () => Item::amount($value),
            static fn () => new OtherTax(OtherTaxType::OTHER, 'Tax', $value, '1'),
        ] as $create) {
            try {
                $create();
                self::fail('Expected invalid integer cents.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public static function invalidAmounts(): array
    {
        return array_map(static fn ($value): array => [$value], [
            1.23,
            1.0,
            NAN,
            INF,
            null,
            true,
            '-1.00',
            '1.001',
            '1e3',
            '1,00',
            ' 1.00',
            '01.00',
            '',
            '.50',
            '10000000000000.00',
            '100',
            -1,
            Amounts::MAX_CENTS + 1,
        ]);
    }

    public function testExactAmountArithmeticAndRange(): void
    {
        self::assertSame('0.30', Amounts::decimal(Amounts::add(10, 20)));
        self::assertSame(Amounts::MAX_CENTS, Item::amount(Amounts::MAX_CENTS)->amount);
        self::assertSame(210000000000000, Amounts::ratio(Amounts::MAX_CENTS, 2100, 10000));
        $this->expectException(InvalidArgumentException::class);
        $this->calculate([Item::netAmount(Amounts::MAX_CENTS, VatRate::ZERO), Item::netAmount(1, VatRate::ZERO)]);
    }

    #[DataProvider('formattedAmounts')]
    public function testSoapAmountsHaveExactlyTwoDecimalPlaces(int $cents, string $expected): void
    {
        self::assertSame($expected, Amounts::decimal($cents));
    }

    public static function formattedAmounts(): array
    {
        return [
            [0,                  '0.00'],
            [1,                  '0.01'],
            [10,                 '0.10'],
            [99,                 '0.99'],
            [100,                '1.00'],
            [101,                '1.01'],
            [Amounts::MAX_CENTS, '9999999999999.99'],
        ];
    }

    #[DataProvider('invalidCents')]
    public function testCentsRejectsNonIntegersEvenFromWeaklyTypedCallers(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $create = require __DIR__ . '/Support/weak-amount-caller.php';
        $create($value);
    }

    public static function invalidCents(): array
    {
        return [[1.0], ['100'], [-1], [Amounts::MAX_CENTS + 1], [null]];
    }

    #[DataProvider('classMatrix')]
    public function testInvoiceClassIsDerivedNotSelected(
        VatCondition $issuer,
        Receiver $receiver,
        InvoiceClass $expected,
    ): void {
        $item = $expected === InvoiceClass::C ? Item::amount(10000) : Item::netAmount(10000, VatRate::STANDARD_21);
        $invoice = new Invoice(2, $receiver, [$item], new DateTimeImmutable('2026-11-14'));
        self::assertSame($expected, InvoiceCalculator::calculate($invoice, $issuer)->class);
    }

    /** Explicit fiscal table (RG 5616), deliberately not re-derived from the implementation. */
    public static function classMatrix(): array
    {
        $ri = VatCondition::RESPONSABLE_INSCRIPTO;
        $business = static fn (VatCondition $condition): Receiver => Receiver::business('20111111112', $condition);
        $receivers = [
            'RI' => [$business($ri), InvoiceClass::A],
            'Monotributista' => [$business(VatCondition::MONOTRIBUTISTA), InvoiceClass::A],
            'Monotributista social' => [
                $business(VatCondition::MONOTRIBUTISTA_SOCIAL),
                InvoiceClass::A,
            ],
            'Monotributo promovido' => [
                $business(VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO),
                InvoiceClass::A,
            ],
            'Exento' => [$business(VatCondition::EXENTO), InvoiceClass::B],
            'No alcanzado' => [$business(VatCondition::NO_ALCANZADO), InvoiceClass::B],
            'CF con CUIT' => [Receiver::finalConsumerWithCuit('20111111112'), InvoiceClass::B],
            'CF con CUIL' => [Receiver::finalConsumerWithCuil('20111111112'), InvoiceClass::B],
            'CF con DNI' => [Receiver::finalConsumerWithDni('30111222'), InvoiceClass::B],
        ];
        $cases = [];
        foreach ($receivers as $name => [$receiver, $fromRi]) {
            $cases["RI -> {$name}"] = [$ri, $receiver, $fromRi];
            foreach ([
                VatCondition::MONOTRIBUTISTA,
                VatCondition::MONOTRIBUTISTA_SOCIAL,
                VatCondition::EXENTO,
                VatCondition::NO_ALCANZADO,
            ] as $issuer) {
                $cases["{$issuer->name} -> {$name}"] = [$issuer, $receiver, InvoiceClass::C];
            }
        }
        return $cases;
    }

    #[DataProvider('voucherTypes')]
    public function testVoucherTypeCodesMatchTheArcaCatalog(InvoiceClass $class, InvoiceKind $kind, int $code): void
    {
        self::assertSame($code, InvoiceMapper::type($class, $kind));
    }

    public static function voucherTypes(): array
    {
        return [
            'Factura A' => [InvoiceClass::A, InvoiceKind::INVOICE, 1],
            'ND A' => [InvoiceClass::A, InvoiceKind::DEBIT_NOTE, 2],
            'NC A' => [InvoiceClass::A, InvoiceKind::CREDIT_NOTE, 3],
            'Factura B' => [InvoiceClass::B, InvoiceKind::INVOICE, 6],
            'ND B' => [InvoiceClass::B, InvoiceKind::DEBIT_NOTE, 7],
            'NC B' => [InvoiceClass::B, InvoiceKind::CREDIT_NOTE, 8],
            'Factura C' => [InvoiceClass::C, InvoiceKind::INVOICE, 11],
            'ND C' => [InvoiceClass::C, InvoiceKind::DEBIT_NOTE, 12],
            'NC C' => [InvoiceClass::C, InvoiceKind::CREDIT_NOTE, 13],
        ];
    }

    public function testVatRateCodesMatchTheArcaCatalog(): void
    {
        $codes = [];
        foreach ([
            VatRate::ZERO,
            VatRate::REDUCED_2_5,
            VatRate::REDUCED_5,
            VatRate::REDUCED_10_5,
            VatRate::STANDARD_21,
            VatRate::INCREASED_27,
        ] as $rate) {
            $codes[$rate->name] = [
                InvoiceMapper::rate($rate),
                InvoiceCalculator::basisPoints($rate),
            ];
            self::assertSame($rate, VatRate::fromArcaCode(InvoiceMapper::rate($rate)));
        }
        self::assertSame(
            [
                'ZERO' => [3, 0],
                'REDUCED_2_5' => [9, 250],
                'REDUCED_5' => [8, 500],
                'REDUCED_10_5' => [
                    4,
                    1050,
                ],
                'STANDARD_21' => [
                    5,
                    2100,
                ],
                'INCREASED_27' => [
                    6,
                    2700,
                ],
            ],
            $codes,
        );
    }

    #[DataProvider('arcaDocuments')]
    public function testReceiverFromArcaDocumentCodes(
        int $type,
        string $number,
        VatCondition $condition,
        ?Receiver $expected,
    ): void {
        if ($expected === null) {
            $this->expectException(InvalidArgumentException::class);
        }
        self::assertEquals($expected, Receiver::fromArcaDocument($type, $number, $condition));
    }

    public static function arcaDocuments(): array
    {
        $final = VatCondition::CONSUMIDOR_FINAL;
        $registered = VatCondition::RESPONSABLE_INSCRIPTO;

        return [
            'business' => [80, '20111111112', $registered, Receiver::business('20111111112', $registered)],
            'final with CUIT' => [80, '20111111112', $final, Receiver::finalConsumerWithCuit('20111111112')],
            'final with CUIL' => [86, '20111111112', $final, Receiver::finalConsumerWithCuil('20111111112')],
            'final with DNI' => [96, '30111222', $final, Receiver::finalConsumerWithDni('30111222')],
            'business without CUIT' => [96, '30111222', $registered, null],
            'unidentified final consumer' => [99, '0', $final, null],
        ];
    }

    public function testTaxTypeCodesMatchTheArcaCatalog(): void
    {
        $codes = [];
        foreach (OtherTaxType::cases() as $type) {
            $codes[$type->name] = InvoiceMapper::taxType($type);
        }
        self::assertSame(
            [
                'NATIONAL' => 1,
                'PROVINCIAL' => 2,
                'MUNICIPAL' => 3,
                'INTERNAL' => 4,
                'GROSS_INCOME' => 5,
                'VAT_PERCEPTION' => 6,
                'GROSS_INCOME_PERCEPTION' => 7,
                'MUNICIPAL_PERCEPTION' => 8,
                'OTHER_PERCEPTION' => 9,
                'OTHER' => 99,
            ],
            $codes,
        );
    }

    public function testInvoiceKindValuesArePersistedTokensAndMustNotChange(): void
    {
        self::assertSame(
            ['INVOICE', 'CREDIT_NOTE', 'DEBIT_NOTE'],
            array_map(static fn (InvoiceKind $kind): string => $kind->value, InvoiceKind::cases()),
        );
    }

    /**
     * Property: for every gross (VAT-included) amount the split is exact (base + VAT = gross) and the VAT
     * stays within one cent of base * rate, which is what ARCA checks per AlicIva row.
     */
    public function testGrossAmountsSplitExactlyAndStayWithinArcaTolerance(): void
    {
        mt_srand(5616);
        foreach ([
            VatRate::REDUCED_2_5,
            VatRate::REDUCED_5,
            VatRate::REDUCED_10_5,
            VatRate::STANDARD_21,
            VatRate::INCREASED_27,
        ] as $rate) {
            $bp = InvoiceCalculator::basisPoints($rate);
            foreach ([
                ...range(1, 300),
                ...array_map(static fn (): int => mt_rand(1, 10_000_000_000), range(1, 300)),
            ] as $cents) {
                $preview = $this->calculate([Item::amount($cents, $rate)]);
                [$row] = $preview->vatByRate;
                self::assertSame($cents, $row->base + $row->amount, "gross {$cents} at {$rate->name}");
                self::assertSame($cents, $preview->total);
                self::assertLessThanOrEqual(
                    1,
                    abs($row->amount - Amounts::ratio($row->base, $bp, 10000)),
                    "gross {$cents} at {$rate->name}",
                );
            }
        }
    }

    /**
     * Property: the wire header always satisfies
     * ImpTotal = ImpNeto + ImpIVA + ImpOpEx + ImpTotConc + ImpTrib, and rows add up.
     */
    public function testWireTotalsAreAlwaysInternallyConsistent(): void
    {
        mt_srand(27);
        $rates = VatRate::cases();
        for ($i = 0; $i < 500; ++$i) {
            $items = [];
            for ($n = mt_rand(1, 6); $n > 0; --$n) {
                $amount = mt_rand(1, 50_000_000);
                $rate = $rates[array_rand($rates)];
                $items[] = mt_rand(0, 1) === 1 ? Item::amount($amount, $rate) : Item::netAmount($amount, $rate);
            }
            $taxes = mt_rand(0, 1) === 1
                ? [new OtherTax(
                    OtherTaxType::GROSS_INCOME_PERCEPTION,
                    'Percepcion IIBB',
                    mt_rand(1, 50_000_000),
                    '3.50',
                )] : [];
            try {
                $invoice = new Invoice(
                    2,
                    Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
                    $items,
                    new DateTimeImmutable('2026-11-14'),
                    otherTaxes: $taxes,
                );
                $preview = InvoiceCalculator::calculate($invoice, VatCondition::RESPONSABLE_INSCRIPTO);
            } catch (InvalidArgumentException) {
                continue; // e.g. an all-zero total
            }
            $detail = InvoiceMapper::detail($invoice, $preview, '20111111112');
            $cents = static fn (string $decimal): int => (int) str_replace('.', '', $decimal);
            self::assertSame(
                $cents($detail['ImpTotal']),
                $cents($detail['ImpNeto'])
                + $cents($detail['ImpIVA'])
                + $cents($detail['ImpOpEx'])
                + $cents($detail['ImpTotConc'])
                + $cents($detail['ImpTrib']),
            );
            $rows = $detail['Iva']['AlicIva'] ?? [];
            self::assertSame(
                $cents($detail['ImpNeto']),
                array_sum(array_map(static fn (array $row): int => $cents($row['BaseImp']), $rows)),
            );
            self::assertSame(
                $cents($detail['ImpIVA']),
                array_sum(array_map(static fn (array $row): int => $cents($row['Importe']), $rows)),
            );
            self::assertSame(count($rows), count(array_unique(array_column($rows, 'Id'))), 'one AlicIva row per rate');
        }
    }

    public function testClassCWireShapeNeverDiscriminatesVat(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::finalConsumerWithDni('30111222'),
            [Item::amount(10000), Item::amount(50)],
            new DateTimeImmutable('2026-11-14'),
            otherTaxes: [new OtherTax(OtherTaxType::MUNICIPAL, 'Tasa municipal', 10050, '1')],
        );
        $preview = InvoiceCalculator::calculate($invoice, VatCondition::MONOTRIBUTISTA);
        $detail = InvoiceMapper::detail($invoice, $preview, '20111111112');
        self::assertSame(InvoiceClass::C, $preview->class);
        self::assertArrayNotHasKey('Iva', $detail);
        // 100.50 * 1% = 1.005 -> Round Half Up gives 1.01, as accountants and most ERPs do.
        self::assertSame(['100.50', '0.00', '0.00', '0.00', '1.01', '101.51'], [
            $detail['ImpNeto'],
            $detail['ImpIVA'],
            $detail['ImpOpEx'],
            $detail['ImpTotConc'],
            $detail['ImpTrib'],
            $detail['ImpTotal'],
        ]);
        self::assertSame([96, '30111222', 5], [
            $detail['DocTipo'],
            $detail['DocNro'],
            $detail['CondicionIVAReceptorId'],
        ]);
        self::assertSame(['PES', '1'], [$detail['MonId'], $detail['MonCotiz']]);
        self::assertArrayNotHasKey('CanMisMonExt', $detail);
    }

    public function testExemptOnlyClassBHasNoVatRows(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::EXENTO),
            [
                Item::netAmount(8000, VatRate::EXEMPT),
                Item::netAmount(2000, VatRate::UNTAXED),
            ],
            new DateTimeImmutable('2026-11-14'),
        );
        $preview = InvoiceCalculator::calculate($invoice, VatCondition::RESPONSABLE_INSCRIPTO);
        $detail = InvoiceMapper::detail($invoice, $preview, '20111111112');
        self::assertSame([InvoiceClass::B, 4], [$preview->class, $detail['CondicionIVAReceptorId']]);
        self::assertArrayNotHasKey('Iva', $detail);
        self::assertSame(['0.00', '0.00', '80.00', '20.00', '100.00'], [
            $detail['ImpNeto'],
            $detail['ImpIVA'],
            $detail['ImpOpEx'],
            $detail['ImpTotConc'],
            $detail['ImpTotal'],
        ]);
    }

    public function testZeroRateIsReportedAsAnAlicIvaRowUnlikeExempt(): void
    {
        $preview = $this->calculate([Item::netAmount(10000, VatRate::ZERO)]);
        self::assertSame(10000, $preview->net);
        self::assertSame(0, $preview->exempt);
        self::assertCount(1, $preview->vatByRate);
        self::assertSame(VatRate::ZERO, $preview->vatByRate[0]->rate);
    }

    public function testVatPerceptionCannotBeCollectedOnClassC(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::amount(10000)],
            new DateTimeImmutable('2026-11-14'),
            otherTaxes: [new OtherTax(OtherTaxType::VAT_PERCEPTION, 'Percepcion IVA', 10000, '3')],
        );
        $this->expectException(InvalidArgumentException::class);
        InvoiceCalculator::calculate($invoice, VatCondition::MONOTRIBUTISTA);
    }

    public function testVatIsRoundedAfterGroupingAndTiesRoundUp(): void
    {
        $preview = $this->calculate([
            Item::netAmount(1, VatRate::STANDARD_21),
            Item::netAmount(1, VatRate::STANDARD_21),
            Item::netAmount(1, VatRate::STANDARD_21),
            Item::netAmount(10, VatRate::REDUCED_5), // alone: .5 cents -> one cent
            Item::netAmount(30, VatRate::REDUCED_5), // grouped: 40 * 5% = two cents, not 1 + 2
        ]);
        self::assertSame(43, $preview->net);
        self::assertSame(3, $preview->vat);
        self::assertSame(46, $preview->total);
        self::assertSame(1, Amounts::ratio(10, 500, 10000));
        self::assertSame(2, Amounts::ratio(30, 500, 10000));
        self::assertSame(1, Amounts::ratio(5, 1, 10));
        self::assertSame(0, Amounts::ratio(4, 1, 10));
    }

    public function testNetGrossExemptUntaxedAndZeroAreDifferentFiscalTreatments(): void
    {
        $preview = $this->calculate([
            Item::netAmount(10000, VatRate::STANDARD_21),
            Item::amount(12100, VatRate::STANDARD_21),
            Item::netAmount(5000, VatRate::REDUCED_10_5),
            Item::amount(1000, VatRate::EXEMPT),
            Item::netAmount(2000, VatRate::UNTAXED),
            Item::netAmount(3000, VatRate::ZERO),
        ]);
        self::assertSame(28000, $preview->net);
        self::assertSame(4725, $preview->vat);
        self::assertSame(35725, $preview->total);
        self::assertCount(3, $preview->vatByRate);
    }

    public function testDollarRateNeverUsesBinaryFloatingPointOrImplicitPaymentCurrency(): void
    {
        self::assertSame('1234.56789', Currency::dollars('1234.567890', true)->exchangeRate);
        self::assertSame('1000', Currency::dollars('1000.000000', false)->exchangeRate);
        self::assertSame('ARS', Currency::pesos()->code);
        $this->expectException(InvalidArgumentException::class);
        Currency::dollars(1234.56789, true);
    }

    public function testCurrencySettingsKeepWsfeBoundariesAndPaymentChoiceExplicit(): void
    {
        self::assertSame('0.000001', Currency::dollars('0.000001', true)->exchangeRate);
        self::assertSame('9999.999999', Currency::dollars('9999.999999', false)->exchangeRate);
        self::assertSame('USD', Currency::dollars('1', true)->code);
        self::assertTrue(Currency::dollars('1', true)->paidInSameCurrency);
        self::assertFalse(Currency::dollars('1', false)->paidInSameCurrency);
        self::assertSame('1', Currency::pesos()->exchangeRate);
        self::assertNull(Currency::pesos()->paidInSameCurrency);
    }

    public function testOtherTaxesAreDerivedFromAnExplicitBaseAndPercentage(): void
    {
        $tax = new OtherTax(OtherTaxType::PROVINCIAL, 'Ingresos Brutos', 15000, '5.20');
        self::assertSame(780, $tax->amount);
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(15000, VatRate::STANDARD_21)],
            new DateTimeImmutable('2026-11-14'),
            otherTaxes: [$tax],
        );
        $preview = InvoiceCalculator::calculate($invoice, VatCondition::RESPONSABLE_INSCRIPTO);
        self::assertSame(18930, $preview->total);
        self::assertSame(780, $preview->otherTaxes);
        $detail = InvoiceMapper::detail($invoice, $preview, '20111111112');
        self::assertSame(
            [
                'Tributo' => [[
                    'Id' => 2,
                    'Desc' => 'Ingresos Brutos',
                    'BaseImp' => '150.00',
                    'Alic' => '5.20',
                    'Importe' => '7.80',
                ]],
            ],
            $detail['Tributos'],
        );
        $this->expectException(InvalidArgumentException::class);
        new OtherTax(OtherTaxType::OTHER, 'Invalid', 15000, '5.201');
    }

    public function testIntegerRoundingAgreesWithIndependentSmallRationalOracle(): void
    {
        foreach ([0, 250, 500, 1050, 2100, 2700] as $rate) {
            for ($cents = 0; $cents <= 1000; ++$cents) {
                $product = $cents * $rate;
                $floor = intdiv($product, 10000);
                $rest = $product % 10000;
                $expected = $floor + ($rest >= 5000 ? 1 : 0);
                self::assertSame($expected, Amounts::ratio($cents, $rate, 10000));
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Amounts::ratio(Amounts::MAX_CENTS, 99999, 1);
    }

    #[DataProvider('badRates')]
    public function testInvalidExchangeRatesAreRejected(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);
        Currency::dollars($rate, false);
    }

    public static function badRates(): array
    {
        return [['0'], ['0.000000'], ['-1'], ['10000'], ['1.0000001'], ['1e3'], ['01'], ['NaN']];
    }

    public function testMapperHidesArcaCodesAndSerializesExactDecimalStrings(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::MONOTRIBUTISTA),
            [Item::netAmount(15000, VatRate::STANDARD_21)],
            new DateTimeImmutable('2026-11-14'),
            new ServicePeriod(
                new DateTimeImmutable('2026-11-01'),
                new DateTimeImmutable('2026-11-14'),
                new DateTimeImmutable('2026-11-30'),
                true,
            ),
            Currency::dollars('1234.567890', false),
            InvoiceKind::CREDIT_NOTE,
            new InvoiceReference(InvoiceClass::A, 2, 49, new DateTimeImmutable('2026-11-13')),
        );
        $preview = InvoiceCalculator::calculate($invoice, VatCondition::RESPONSABLE_INSCRIPTO);
        $detail = InvoiceMapper::detail($invoice, $preview, '20111111112');
        self::assertSame('181.50', $detail['ImpTotal']);
        self::assertSame(['AlicIva' => [['Id' => 5, 'BaseImp' => '150.00', 'Importe' => '31.50']]], $detail['Iva']);
        self::assertSame(6, $detail['CondicionIVAReceptorId']);
        self::assertSame(3, $detail['Concepto']);
        self::assertSame('20261101', $detail['FchServDesde']);
        self::assertSame('DOL', $detail['MonId']);
        self::assertSame('N', $detail['CanMisMonExt']);
        self::assertSame('20111111112', $detail['CbtesAsoc']['CbteAsoc'][0]['Cuit']);
        self::assertSame(3, InvoiceMapper::type($preview->class, $invoice->kind));
    }

    public function testDatesUseCalendarDaysNotInstantsOrTheProcessTimezone(): void
    {
        $date = new DateTimeImmutable('2026-11-14T23:30:00-12:00');
        $earlierTime = new DateTimeImmutable('2026-11-14T00:30:00+14:00');
        $invoice = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::netAmount(10000, VatRate::STANDARD_21)],
            $date,
            service: new ServicePeriod($date, $earlierTime, $earlierTime),
            kind: InvoiceKind::CREDIT_NOTE,
            original: new InvoiceReference(InvoiceClass::A, 2, 49, $date),
        );
        $totals = InvoiceCalculator::calculate($invoice, VatCondition::RESPONSABLE_INSCRIPTO);
        $detail = InvoiceMapper::detail($invoice, $totals, '20111111112');

        foreach (['CbteFch', 'FchServDesde', 'FchServHasta', 'FchVtoPago'] as $field) {
            self::assertSame('20261114', $detail[$field]);
        }
        self::assertSame('20261114', $detail['CbtesAsoc']['CbteAsoc'][0]['CbteFch']);
        self::assertSame($date, $invoice->date);
    }

    public function testServiceDatesCannotReverseCalendarOrderEvenWhenInstantsAreOrdered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServicePeriod(
            new DateTimeImmutable('2026-11-15T00:30:00+14:00'),
            new DateTimeImmutable('2026-11-14T23:30:00-12:00'),
            new DateTimeImmutable('2026-11-30'),
        );
    }

    #[DataProvider('invalidSerializedDates')]
    public function testPersistedDatesCannotBeSilentlyNormalizedByPhp(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceMapper::parseDate($value);
    }

    public static function invalidSerializedDates(): array
    {
        return [
            ['2026-02-30'],
            ['2026-02-29'],
            ['2026-13-01'],
            ['2026-00-01'],
            ['2026-11-00'],
            ['2026-1-01'],
            ['2026-11-14T00:00:00Z'],
            [''],
        ];
    }

    public function testMaximumClassCAmountSurvivesThePublicApiAndSoapMappingExactly(): void
    {
        $invoice = new Invoice(
            2,
            Receiver::finalConsumerWithDni('30111222'),
            [Item::amount(999_999_999_999_999)],
            new DateTimeImmutable('2026-11-14'),
        );
        $preview = InvoiceCalculator::calculate($invoice, VatCondition::MONOTRIBUTISTA);
        self::assertSame(999_999_999_999_999, $preview->total);
        self::assertSame('9999999999999.99', InvoiceMapper::detail($invoice, $preview, '20111111112')['ImpTotal']);
    }

    public function testTotalCannotOverflowTheFiscalLimitWhenVatIsAdded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate([Item::netAmount(999_999_999_999_999, VatRate::STANDARD_21)]);
    }

    #[DataProvider('invalidDomain')]
    public function testInvalidFiscalIntentCannotReachInfrastructure(string $case): void
    {
        $this->expectException(InvalidArgumentException::class);
        $receiver = Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO);
        $date = new DateTimeImmutable('2026-11-14');
        $item = Item::netAmount(10000, VatRate::STANDARD_21);
        match ($case) {
            'date' => InvoiceMapper::parseDate('2026-02-30'),
            'cuit' => Receiver::business('20111111113', VatCondition::RESPONSABLE_INSCRIPTO),
            'cuit-10' => Receiver::validateCuit('20000000009'),
            'dni' => Receiver::finalConsumerWithDni('0'),
            'empty' => new Invoice(2, $receiver, [], $date),
            'point' => new Invoice(0, $receiver, [$item], $date),
            'note' => new Invoice(2, $receiver, [$item], $date, kind: InvoiceKind::CREDIT_NOTE),
            'period' => new ServicePeriod($date, new DateTimeImmutable('2026-11-01'), $date),
            'due' => new Invoice(
                2,
                $receiver,
                [$item],
                $date,
                new ServicePeriod($date, $date, new DateTimeImmutable('2026-11-13')),
            ),
            'c-vat' => InvoiceCalculator::calculate(
                new Invoice(2, $receiver, [$item], $date),
                VatCondition::MONOTRIBUTISTA,
            ),
            'a-missing-vat' => $this->calculate([Item::amount(10000)]),
            'zero' => $this->calculate([Item::netAmount(0, VatRate::ZERO)]),
            'invoice-with-period' => new Invoice(
                2,
                $receiver,
                [$item],
                $date,
                period: new AssociatedPeriod($date, $date),
            ),
            'note-with-original-and-period' => new Invoice(
                2,
                $receiver,
                [$item],
                $date,
                kind: InvoiceKind::CREDIT_NOTE,
                original: new InvoiceReference(InvoiceClass::A, 2, 1, $date),
                period: new AssociatedPeriod($date, $date),
            ),
            'credit-note-of-credit-note' => new Invoice(
                2,
                $receiver,
                [$item],
                $date,
                kind: InvoiceKind::CREDIT_NOTE,
                original: new InvoiceReference(InvoiceClass::A, 2, 1, $date, InvoiceKind::CREDIT_NOTE),
            ),
            'reversed-associated-period' => new AssociatedPeriod($date, new DateTimeImmutable('2026-11-13')),
            'note-items-differ-from-original-class' => InvoiceCalculator::calculate(
                new Invoice(
                    2,
                    $receiver,
                    [$item],
                    $date,
                    kind: InvoiceKind::CREDIT_NOTE,
                    original: new InvoiceReference(InvoiceClass::C, 2, 1, $date),
                ),
                VatCondition::RESPONSABLE_INSCRIPTO,
            ),
        };
    }

    public static function invalidDomain(): array
    {
        return array_map(static fn ($case): array => [$case], [
            'date',
            'cuit',
            'cuit-10',
            'dni',
            'empty',
            'point',
            'note',
            'period',
            'due',
            'c-vat',
            'a-missing-vat',
            'zero',
            'invoice-with-period',
            'note-with-original-and-period',
            'credit-note-of-credit-note',
            'reversed-associated-period',
            'note-items-differ-from-original-class',
        ]);
    }

    #[DataProvider('itemVatMismatches')]
    public function testItemVatErrorsNameTheIssuerCondition(
        VatCondition $issuer,
        ?InvoiceClass $original,
        Item $item,
        string $message,
    ): void {
        $date = new DateTimeImmutable('2026-11-14');
        $receiver = Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO);
        $invoice = $original === null
            ? new Invoice(2, $receiver, [$item], $date)
            : new Invoice(
                2,
                $receiver,
                [$item],
                $date,
                kind: InvoiceKind::CREDIT_NOTE,
                original: new InvoiceReference($original, 2, 1, $date),
            );
        $this->expectExceptionMessage($message);
        InvoiceCalculator::calculate($invoice, $issuer);
    }

    public static function itemVatMismatches(): array
    {
        $plain = Item::amount(10000);
        $taxed = Item::amount(12100, VatRate::STANDARD_21);

        return [
            'responsable inscripto without rate' => [
                VatCondition::RESPONSABLE_INSCRIPTO,
                null,
                $plain,
                'A Responsable Inscripto must give each item a VAT rate',
            ],
            'monotributista with rate' => [
                VatCondition::MONOTRIBUTISTA,
                null,
                $taxed,
                'A Monotributista or Exento issuer does not discriminate VAT',
            ],
            'note on class C with rate' => [
                VatCondition::RESPONSABLE_INSCRIPTO,
                InvoiceClass::C,
                $taxed,
                'A note on a class C voucher does not discriminate VAT',
            ],
            'note on class A without rate' => [
                VatCondition::MONOTRIBUTISTA,
                InvoiceClass::A,
                $plain,
                'A note on a class A or B voucher must give each item a VAT rate',
            ],
        ];
    }

    public function testReceiverDocumentTypesMapToTheirWsfeCodes(): void
    {
        $date = new DateTimeImmutable('2026-11-14');
        $cases = [
            [Receiver::business('20111111112', VatCondition::EXENTO), DocumentType::CUIT, 80],
            [Receiver::finalConsumerWithCuit('20111111112'),       DocumentType::CUIT, 80],
            [Receiver::finalConsumerWithCuil('20111111112'),       DocumentType::CUIL, 86],
            [Receiver::finalConsumerWithDni('30111222'),           DocumentType::DNI,  96],
        ];
        foreach ($cases as [$receiver, $type, $code]) {
            $invoice = new Invoice(2, $receiver, [Item::netAmount(10000, VatRate::STANDARD_21)], $date);
            $detail = InvoiceMapper::detail(
                $invoice,
                InvoiceCalculator::calculate($invoice, VatCondition::RESPONSABLE_INSCRIPTO),
                '20111111112',
            );
            self::assertSame($type, $receiver->documentType);
            self::assertSame($code, $detail['DocTipo']);
        }
        $this->expectException(InvalidArgumentException::class);
        Receiver::finalConsumerWithCuil('20111111113');
    }

    public function testNotesReferenceTheirOriginalVoucherTypeOrAnAssociatedPeriod(): void
    {
        $date = new DateTimeImmutable('2026-11-14');
        $receiver = Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO);
        $items = [Item::netAmount(10000, VatRate::STANDARD_21)];
        $detail = static function (
            InvoiceKind $kind,
            ?InvoiceReference $original,
            ?AssociatedPeriod $period = null,
        ) use ($date, $receiver, $items): array {
            $note = new Invoice(2, $receiver, $items, $date, kind: $kind, original: $original, period: $period);
            $preview = InvoiceCalculator::calculate($note, VatCondition::RESPONSABLE_INSCRIPTO);
            return InvoiceMapper::detail($note, $preview, '20111111112');
        };
        // Credit note A adjusting debit note A (type 2); debit note A adjusting credit note A (type 3).
        $creditOfDebit = $detail(
            InvoiceKind::CREDIT_NOTE,
            new InvoiceReference(InvoiceClass::A, 2, 4, $date, InvoiceKind::DEBIT_NOTE),
        );
        self::assertSame(2, $creditOfDebit['CbtesAsoc']['CbteAsoc'][0]['Tipo']);
        $debitOfCredit = $detail(
            InvoiceKind::DEBIT_NOTE,
            new InvoiceReference(InvoiceClass::A, 2, 5, $date, InvoiceKind::CREDIT_NOTE),
        );
        self::assertSame(3, $debitOfCredit['CbtesAsoc']['CbteAsoc'][0]['Tipo']);

        $october = new AssociatedPeriod(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-31'));
        $period = $detail(InvoiceKind::CREDIT_NOTE, null, $october);
        self::assertSame(['FchDesde' => '20261001', 'FchHasta' => '20261031'], $period['PeriodoAsoc']);
        self::assertArrayNotHasKey('CbtesAsoc', $period);
    }

    public function testNoteKeepsTheOriginalClassAfterTheIssuerConditionChanged(): void
    {
        // A former monotributista, now Responsable Inscripto, cancels an old class C invoice.
        $date = new DateTimeImmutable('2026-11-14');
        $note = new Invoice(
            2,
            Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
            [Item::amount(10000)],
            $date,
            kind: InvoiceKind::CREDIT_NOTE,
            original: new InvoiceReference(InvoiceClass::C, 2, 7, $date),
        );
        $preview = InvoiceCalculator::calculate($note, VatCondition::RESPONSABLE_INSCRIPTO);
        self::assertSame(InvoiceClass::C, $preview->class);
        self::assertSame(13, InvoiceMapper::type($preview->class, InvoiceKind::CREDIT_NOTE));
        self::assertSame(0, $preview->vat);
    }

    private function calculate(array $items): \Afiphp\Billing\Preview
    {
        return InvoiceCalculator::calculate(
            new Invoice(
                2,
                Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
                $items,
                new DateTimeImmutable('2026-11-14'),
            ),
            VatCondition::RESPONSABLE_INSCRIPTO,
        );
    }
}
