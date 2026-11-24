<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Billing\VatCondition;
use Afiphp\Internal\Billing\InvoiceMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatConditionTest extends TestCase
{
    #[DataProvider('fiscalConditions')]
    public function testFiscalCodesDoNotChangeWithPublicNames(VatCondition $condition, int $code): void
    {
        self::assertSame($code, InvoiceMapper::condition($condition));
        self::assertSame($condition, VatCondition::fromArcaCode($code));
    }

    public function testUnknownArcaCodeIsNotGuessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VatCondition::fromArcaCode(2);
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
