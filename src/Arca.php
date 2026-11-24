<?php

declare(strict_types=1);

namespace Afiphp;

use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\Preview;
use Afiphp\Billing\Taxpayer;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Internal\Billing\FiscalGateway;
use Afiphp\Internal\Billing\FoundVoucher;
use Afiphp\Internal\Billing\InvoiceCalculator;
use Afiphp\Internal\Billing\InvoiceMapper;
use Afiphp\Internal\Billing\Issuance;
use Afiphp\Internal\Billing\VoucherReader;
use Afiphp\Internal\WebServices\Failures;
use Afiphp\Internal\WebServices\PadronA13;
use Afiphp\Internal\WebServices\PadronA5;
use Afiphp\Internal\WebServices\WsFeV1;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** Public entry point. No ARCA codes, SOAP requests, or authentication calls required. */
final class Arca
{
    private FiscalGateway $gateway;
    private Issuance $issuance;
    private PadronA5 $padron;
    private PadronA13 $documents;

    /**
     * @param FiscalGateway|null $gateway Internal testing seam, not an application integration API.
     * @param PadronA5|null $padron Internal testing seam, not an application integration API.
     * @param PadronA13|null $documents Internal testing seam, not an application integration API.
     */
    public function __construct(
        private readonly Configuration $configuration,
        ?FiscalGateway $gateway = null,
        ?PadronA5 $padron = null,
        ?PadronA13 $documents = null,
    ) {
        $this->gateway = $gateway ?? new WsFeV1($configuration);
        $this->issuance = new Issuance($configuration, $this->gateway);
        $this->padron = $padron ?? new PadronA5($configuration);
        $this->documents = $documents ?? new PadronA13($configuration);
    }

    /**
     * ARCA's registry (constancia de inscripción): name, fiscal address and VAT condition of a CUIT.
     * Null when the CUIT does not exist. Requires the certificate to be enabled for ws_sr_constancia_inscripcion.
     */
    public function taxpayer(string $cuit): ?Taxpayer
    {
        return $this->padron->taxpayer($cuit);
    }

    /**
     * The CUITs/CUILs ARCA's registry (Padrón A13) associates with a DNI; usually one, empty when none.
     * Requires the certificate to be enabled for ws_sr_padron_a13.
     * @return list<string>
     */
    public function cuitsByDni(string $dni): array
    {
        return $this->documents->cuitsByDni($dni);
    }

    /**
     * WSFE's own health report (FEDummy), without the certificate: whether ARCA is working for everyone,
     * not whether this issuer can invoice. Throws ArcaUnavailable when ARCA cannot be reached at all.
     */
    public function status(): ServiceStatus
    {
        return $this->gateway->status();
    }

    /** Days before the certificate expires when check() starts logging a warning. */
    public const CERTIFICATE_WARNING_DAYS = 30;

    /**
     * Whether this issuer can invoice now: the certificate is in force, ARCA is working, the
     * certificate logs in and WSFE is enabled for the CUIT. With $salesPoint, also that ARCA lists
     * it for web services and it is active. Returns nothing when it can; otherwise throws
     * ArcaUnavailable (wait) or ConfigurationError (call an administrator). Logs a warning when the
     * certificate expires within CERTIFICATE_WARNING_DAYS. Up to two calls: cache the answer for a minute.
     */
    public function check(?int $salesPoint = null): void
    {
        $credentials = $this->configuration->credentials;
        $now = new DateTimeImmutable();
        if (!$credentials->isValidAt($now)) {
            throw new ConfigurationError(
                $now < $credentials->validFrom
                    ? 'The certificate is not valid until ' . $credentials->validFrom->format(DATE_ATOM) . '.'
                    : 'The certificate expired on ' . $credentials->expiresAt->format(DATE_ATOM) . '.',
            );
        }
        $days = intdiv($credentials->expiresAt->getTimestamp() - $now->getTimestamp(), 86400);
        if ($days < self::CERTIFICATE_WARNING_DAYS) {
            $this->configuration->logger->warning('The ARCA certificate expires in {days} days, on {date}', [
                'days' => $days,
                'date' => $credentials->expiresAt->format(DATE_ATOM),
            ]);
        }
        if (!$this->status()->isAvailable()) {
            throw new ArcaUnavailable('ARCA reports a problem in its own servers.', true);
        }
        $points = $this->gateway->salesPoints();
        if ($salesPoint === null) {
            return;
        }
        foreach ($points as $point) {
            if ($point->number !== $salesPoint) {
                continue;
            }
            if (!$point->isActive()) {
                throw new ConfigurationError(
                    "Sales point {$salesPoint} is " . ($point->blocked ? 'blocked' : 'deregistered') . ' in ARCA.',
                );
            }

            return;
        }
        // ARCA's homologation usually lists no sales points at all, and still accepts any.
        if ($points === [] && $this->configuration->environment === Environment::HOMOLOGATION) {
            return;
        }

        throw new ConfigurationError("ARCA does not list sales point {$salesPoint} for web services.");
    }

    /**
     * The sales points ARCA lists for web services, including blocked and deregistered ones.
     * Empty when there are none; ARCA's homologation usually lists none.
     * @return list<SalesPoint>
     */
    public function salesPoints(): array
    {
        return $this->gateway->salesPoints();
    }

    /** The last number ARCA authorized in a series; 0 when there is none yet. */
    public function lastNumber(int $salesPoint, InvoiceClass $class, InvoiceKind $kind = InvoiceKind::INVOICE): int
    {
        self::assertCoordinates($salesPoint, 1);

        return $this->gateway->lastNumber(InvoiceMapper::type($class, $kind), $salesPoint);
    }

    /**
     * An authorized voucher as ARCA has it (FECompConsultar), or null when the number is empty.
     * Works for vouchers issued by any system; throws UnexpectedValueException for one afiphp does not
     * model, such as one in euros or authorized with CAEA.
     */
    public function voucher(
        InvoiceClass $class,
        int $salesPoint,
        int $number,
        InvoiceKind $kind = InvoiceKind::INVOICE,
    ): ?IssueResult {
        $found = $this->find($class, $salesPoint, $number, $kind);

        return $found === null ? null : VoucherReader::result($found, $this->configuration->cuit);
    }

    /**
     * A credit note for the whole voucher: it looks the original up in ARCA and mirrors its receiver,
     * currency, exchange rate, amounts by VAT rate and other taxes. Issue it with issue(), like any
     * invoice; keep it, or build it again, for retryIssue(). Throws
     * InvalidArgumentException when ARCA does not have the original with that date, or when its amounts
     * cannot be reproduced exactly: then issue the note with explicit items.
     */
    public function fullCreditNote(
        InvoiceReference $original,
        DateTimeImmutable $date,
        ?int $salesPoint = null,
    ): Invoice {
        $found = $this->find($original->class, $original->salesPoint, $original->number, $original->kind);
        if ($found === null) {
            throw new InvalidArgumentException(
                "ARCA has no voucher {$original->salesPoint}-{$original->number} of that class and kind.",
            );
        }
        if ($found->date->format('Y-m-d') !== $original->date->format('Y-m-d')) {
            throw new InvalidArgumentException(
                "ARCA has voucher {$original->salesPoint}-{$original->number} dated "
                . $found->date->format('Y-m-d')
                . ', not '
                . $original->date->format('Y-m-d')
                . '.',
            );
        }

        return VoucherReader::fullCreditNote(
            $found,
            $date,
            $salesPoint ?? $original->salesPoint,
            $this->configuration->vatCondition,
        );
    }

    /**
     * ARCA's dollar rate (FEParamGetCotizacion), ready for Currency::dollars(). A reference only:
     * the application still asserts the fiscal rate it invoices with.
     */
    public function dollarRate(?DateTimeImmutable $date = null): string
    {
        return $this->gateway->dollarRate($date);
    }

    /** Calculate and validate without contacting ARCA or reserving a number. */
    public function preview(Invoice $invoice): Preview
    {
        return InvoiceCalculator::calculate($invoice, $this->configuration->vatCondition);
    }

    /**
     * Sends a new invoice with the next number. If the answer is lost, looks up that number once.
     * If UnconfirmedIssue is thrown, keep its number with the invoice for retryIssue().
     */
    public function issue(Invoice $invoice): IssueResult
    {
        // PHP otherwise ignores extra positional arguments to user-defined methods.
        if (func_num_args() > 1) {
            throw new InvalidArgumentException('Use retryIssue() to pass an unconfirmed voucher number.');
        }

        return $this->issuance->issue($invoice);
    }

    /**
     * Resolves an unconfirmed attempt before issuing again. The invoice must match the original
     * attempt except for its date, which must be one that ARCA still accepts. Throws RuntimeException
     * when ARCA reports the number as used but cannot find it: reconcile that voucher manually.
     */
    public function retryIssue(Invoice $invoice, int $unconfirmedNumber): IssueResult
    {
        return $this->issuance->retryIssue($invoice, $unconfirmedNumber);
    }

    private function find(InvoiceClass $class, int $salesPoint, int $number, InvoiceKind $kind): ?FoundVoucher
    {
        self::assertCoordinates($salesPoint, $number);
        try {
            $found = $this->gateway->lookup(InvoiceMapper::type($class, $kind), $salesPoint, $number);
        } catch (Throwable $exception) {
            if (Failures::isNotFound($exception)) {
                return null;
            }
            throw $exception;
        }

        return VoucherReader::read($found, $class, $kind, $salesPoint, $number);
    }

    private static function assertCoordinates(int $salesPoint, int $number): void
    {
        if ($salesPoint < 1 || $salesPoint > 99999 || $number < 1 || $number > 99999999) {
            throw new InvalidArgumentException(
                'Sales point must be from 1 through 99999 and number from 1 through 99999999.',
            );
        }
    }
}
