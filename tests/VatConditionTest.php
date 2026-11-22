<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Billing\VatCondition;
use Afiphp\Internal\Billing\InvoiceMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatConditionTest extends TestCase
{
    public function testPublicCasesUseArgentineFiscalNamesWithoutEnglishAliases(): void
    {
        self::assertSame(
            [
                'RESPONSABLE_INSCRIPTO',
                'MONOTRIBUTISTA',
                'MONOTRIBUTISTA_SOCIAL',
                'MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO',
                'EXENTO',
                'NO_ALCANZADO',
                'CONSUMIDOR_FINAL',
            ],
            array_map(static fn (VatCondition $condition): string => $condition->name, VatCondition::cases()),
        );
        foreach (['REGISTERED', 'MONOTAX', 'EXEMPT', 'NOT_SUBJECT', 'FINAL_CONSUMER'] as $oldCase) {
            self::assertFalse(defined(VatCondition::class . '::' . $oldCase));
        }
    }

    #[DataProvider('fiscalConditions')]
    public function testFiscalCodesDoNotChangeWithPublicNames(VatCondition $condition, int $code): void
    {
        self::assertSame($code, InvoiceMapper::condition($condition));
    }

    public static function fiscalConditions(): array
    {
        return [
            [VatCondition::RESPONSABLE_INSCRIPTO,                          1],
            [VatCondition::MONOTRIBUTISTA,                                 6],
            [VatCondition::MONOTRIBUTISTA_SOCIAL,                          13],
            [VatCondition::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO, 16],
            [VatCondition::EXENTO,                                         4],
            [VatCondition::NO_ALCANZADO,                                   15],
            [VatCondition::CONSUMIDOR_FINAL,                               5],
        ];
    }
}
