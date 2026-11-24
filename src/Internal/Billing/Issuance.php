<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Authorization;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceReference;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\Preview;
use Afiphp\Billing\QrCode;
use Afiphp\Configuration;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Exceptions\InvoiceRejected;
use Afiphp\Exceptions\LockTimeout;
use Afiphp\Exceptions\UnconfirmedIssue;
use Afiphp\Internal\WebServices\Failures;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * @internal One submission, and one lookup when its answer is lost. Nothing is stored: the application
 * keeps the number of an unconfirmed attempt and passes it back as $previousNumber. Issuances on one
 * sales point are serialized through the storage, so two workers never race for the same number.
 */
final class Issuance
{
    public function __construct(
        private Configuration $configuration,
        private FiscalGateway $gateway,
    ) {}

    public function issue(Invoice $invoice, ?int $previousNumber): IssueResult
    {
        $totals = InvoiceCalculator::calculate($invoice, $this->configuration->vatCondition);
        if ($previousNumber !== null && ($previousNumber < 1 || $previousNumber > 99999999)) {
            throw new \InvalidArgumentException('The previous number must be from 1 through 99999999.');
        }
        $configuration = $this->configuration;
        $key = "{$configuration->environment->value}-{$configuration->cuit}-{$invoice->salesPoint}";
        $logger = $configuration->logger;
        $context = [
            'salesPoint' => $invoice->salesPoint,
            'class' => $totals->class->value,
            'kind' => $invoice->kind->name,
        ];

        try {
            $result = $configuration->storage->withSalesPointLock(
                $key,
                $configuration->network->salesPointLockTimeout,
                fn (): IssueResult => $this->issueLocked($invoice, $totals, $previousNumber),
            );
        } catch (InvoiceRejected $exception) {
            $logger->warning(
                'ARCA rejected voucher {number}: {observations}',
                $context
                + [
                    'number' => $exception->number,
                    'observations' => implode(' | ', $exception->observations),
                ],
            );
            throw $exception;
        } catch (UnconfirmedIssue $exception) {
            $logger->error(
                'ARCA did not confirm voucher {number}; keep it as previousNumber',
                $context
                + [
                    'number' => $exception->number,
                    'exception' => $exception,
                ],
            );
            throw $exception;
        } catch (ConfigurationError $exception) {
            // Nobody can invoice until an administrator fixes it.
            $logger->error(
                'ARCA refused the issuer configuration: {message}',
                $context
                + [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ],
            );
            throw $exception;
        } catch (ArcaUnavailable|LockTimeout $exception) {
            $logger->warning(
                'The voucher was not issued: {message}',
                $context
                + [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ],
            );
            throw $exception;
        }
        $logger->info(
            'ARCA authorized voucher {number} with CAE {cae}',
            $context
            + [
                'number' => $result->number,
                'cae' => $result->authorization->cae,
            ],
        );

        return $result;
    }

    private function issueLocked(Invoice $invoice, Preview $totals, ?int $previousNumber): IssueResult
    {
        $type = InvoiceMapper::type($totals->class, $invoice->kind);
        $detail = InvoiceMapper::detail($invoice, $totals, $this->configuration->cuit);
        $number = null;

        if ($previousNumber !== null) {
            // The earlier attempt may have landed on another day: its date does not identify it.
            $earlier = $this->lookup($invoice, $totals, $type, $detail, $previousNumber, anyDate: true);
            if ($earlier instanceof IssueResult) {
                return $earlier;
            }
            if ($earlier instanceof ArcaUnavailable || $earlier instanceof ConfigurationError) {
                // Nothing was sent this time: the earlier attempt is just as unconfirmed as before.
                throw $earlier;
            }
            if ($earlier instanceof Throwable) {
                throw new UnconfirmedIssue($previousNumber, $earlier);
            }
            // Still empty: resending that number can never duplicate, because ARCA only accepts the next one.
            // Another voucher there means the earlier attempt never landed: issue as new.
            $number = $earlier === 'not_found' ? $previousNumber : null;
        }

        $number ??= $this->gateway->lastNumber($type, $invoice->salesPoint) + 1;
        if ($number > 99999999) {
            throw new RuntimeException('The fiscal sequence is exhausted. Nothing was sent.');
        }
        $detail['CbteDesde'] = $detail['CbteHasta'] = $number;
        $request = self::request($invoice->salesPoint, $type, $detail);
        $failure = null;
        try {
            $evidence = FiscalEvidence::authorization(
                $this->gateway->authorize($request),
                $request,
                $this->configuration->cuit,
            );
        } catch (ConfigurationError|LockTimeout $exception) {
            // ARCA refused the request before processing it, or the login waited for another worker:
            // nothing was issued.
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof ArcaUnavailable && !$exception->sent) {
                throw $exception;
            }
            // The request may have reached ARCA.
            $evidence = ['status' => 'unknown'];
            $failure = $exception;
        }

        if ($evidence['status'] === 'authorized') {
            return $this->authorized(
                $invoice,
                $totals,
                $number,
                $invoice->date,
                new Authorization($evidence['cae'], InvoiceMapper::parseDate($evidence['expires'])),
                $evidence['observations'],
            );
        }
        $resent = $number === $previousNumber;
        if ($evidence['status'] === 'rejected' && !$resent) {
            throw new InvoiceRejected($number, $evidence['observations']);
        }

        // The answer was lost, or ARCA refused a resend that the earlier attempt may have beaten to the number.
        $found = $this->lookup($invoice, $totals, $type, $detail, $number, anyDate: $resent);
        if ($found instanceof IssueResult) {
            return $found;
        }
        if ($evidence['status'] === 'rejected') {
            throw new InvoiceRejected($number, $evidence['observations']);
        }

        throw new UnconfirmedIssue($number, $failure ?? ($found instanceof Throwable ? $found : null));
    }

    /**
     * ARCA's voucher at $number as this invoice's result, 'not_found', 'other' for a different voucher,
     * or the failure that left it unknown.
     */
    private function lookup(
        Invoice $invoice,
        Preview $totals,
        int $type,
        array $detail,
        int $number,
        bool $anyDate,
    ): IssueResult|string|Throwable {
        $detail['CbteDesde'] = $detail['CbteHasta'] = $number;
        try {
            $found = $this->gateway->lookup($type, $invoice->salesPoint, $number);
        } catch (Throwable $exception) {
            return Failures::isNotFound($exception) ? 'not_found' : $exception;
        }
        $evidence = FiscalEvidence::lookup($found, self::request($invoice->salesPoint, $type, $detail), $anyDate);

        return match ($evidence['status']) {
            'authorized' => $this->authorized(
                $invoice,
                $totals,
                $number,
                InvoiceMapper::parseDate($evidence['date']),
                new Authorization($evidence['cae'], InvoiceMapper::parseDate($evidence['expires'])),
                [],
            ),
            'conflict' => 'other',
            default => new RuntimeException("ARCA's voucher could not be compared: {$evidence['reason']}."),
        };
    }

    /** @param list<string> $observations */
    private function authorized(
        Invoice $invoice,
        Preview $totals,
        int $number,
        DateTimeImmutable $date,
        Authorization $authorization,
        array $observations,
    ): IssueResult {
        $voucher = new InvoiceReference($totals->class, $invoice->salesPoint, $number, $date, $invoice->kind);

        return new IssueResult(
            $totals->class,
            $invoice->kind,
            $invoice->salesPoint,
            $number,
            $date,
            $totals,
            $authorization,
            $observations,
            QrCode::url(
                $this->configuration->cuit,
                $voucher,
                $totals->total,
                $authorization->cae,
                $invoice->receiver,
                $invoice->currency,
            ),
            $invoice->receiver,
            $invoice->currency,
        );
    }

    private static function request(int $salesPoint, int $type, array $detail): array
    {
        return [
            'FeCAEReq' => [
                'FeCabReq' => ['CantReg' => 1, 'PtoVta' => $salesPoint, 'CbteTipo' => $type],
                'FeDetReq' => ['FECAEDetRequest' => [$detail]],
            ],
        ];
    }
}
