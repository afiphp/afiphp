<?php

declare(strict_types=1);

namespace Afiphp\Billing;

use DateTimeImmutable;

/** A result is evidence, never implicit permission to submit again. Totals are in integer cents. */
final readonly class IssueResult
{
    /**
     * @param list<string> $observations
     * @param string|null $qr RG 4892 QR URL for the printed voucher; authorized results only.
     * @internal Constructed by the issuance coordinator.
     */
    public function __construct(
        public Outcome $outcome,
        public string $operation,
        public InvoiceClass $class,
        public InvoiceKind $kind,
        public int $salesPoint,
        public ?int $number,
        public DateTimeImmutable $date,
        public Preview $totals,
        public ?Authorization $authorization = null,
        public array $observations = [],
        public ?string $reason = null,
        public ?string $blockedBy = null,
        public bool $reconciled = false,
        public ?string $qr = null,
    ) {}

    public function invoiceReference(): InvoiceReference
    {
        if ($this->outcome !== Outcome::AUTHORIZED || $this->number === null) {
            throw new \LogicException('Only an authorized voucher can be referenced by a note.');
        }

        return new InvoiceReference($this->class, $this->salesPoint, $this->number, $this->date, $this->kind);
    }
}
