<?php

declare(strict_types=1);

namespace Afiphp\Printing;

use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use DateTimeImmutable;
use InvalidArgumentException;

/** The issuer data a printed voucher shows and ARCA does not keep (RG 1415, Anexo II). */
final readonly class IssuerDetails
{
    /**
     * @param string $address Where the voucher is issued (domicilio comercial).
     * @param string $grossIncome The ingresos brutos number, or the non-contributor condition.
     * @param DateTimeImmutable|null $activitiesStart Null only for university professionals billing their
     *     fees and service providers without premises.
     * @param string|null $tradeName Only when the issuer has one (nombre de fantasía).
     * @param bool $paymentToInformedCbu ARCA enabled class A vouchers "PAGO EN CBU INFORMADA" (RG 5762).
     */
    public function __construct(
        public string $cuit,
        public VatCondition $vatCondition,
        public string $legalName,
        public string $address,
        public string $grossIncome,
        public ?DateTimeImmutable $activitiesStart,
        public ?string $tradeName = null,
        public bool $paymentToInformedCbu = false,
    ) {
        Receiver::validateCuit($cuit);
        if ($vatCondition === VatCondition::CONSUMIDOR_FINAL) {
            throw new InvalidArgumentException('An issuer cannot be a final consumer.');
        }
        foreach ([
            'legalName' => $legalName,
            'address' => $address,
            'grossIncome' => $grossIncome,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("The issuer's {$field} is required on a printed voucher.");
            }
        }
        if ($tradeName !== null && trim($tradeName) === '') {
            throw new InvalidArgumentException('Omit the trade name instead of leaving it empty.');
        }
    }
}
