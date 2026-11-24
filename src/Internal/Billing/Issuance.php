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
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** @internal Serializes emission and reconciliation for each sales point. */
final class Issuance
{
    public function __construct(
        private Configuration $configuration,
        private FiscalGateway $gateway,
    ) {}

    public function issue(Invoice $invoice): IssueResult
    {
        return $this->withSalesPointLock($invoice, function (Preview $totals, int $type) use ($invoice): IssueResult {
            $number = $this->gateway->lastNumber($type, $invoice->salesPoint) + 1;

            return $this->issueNumber($invoice, $totals, $type, $number, FiscalEvidence::lookup(...));
        });
    }

    public function retryIssue(Invoice $invoice, int $unconfirmedNumber): IssueResult
    {
        if ($unconfirmedNumber < 1 || $unconfirmedNumber > 99999999) {
            throw new InvalidArgumentException('The unconfirmed number must be from 1 through 99999999.');
        }

        return $this->withSalesPointLock($invoice, fn (Preview $totals, int $type): IssueResult => $this->retryLocked(
            $invoice,
            $totals,
            $type,
            $unconfirmedNumber,
        ));
    }

    /** @param callable(Preview, int): IssueResult $operation */
    private function withSalesPointLock(Invoice $invoice, callable $operation): IssueResult
    {
        $totals = InvoiceCalculator::calculate($invoice, $this->configuration->vatCondition);
        $type = InvoiceMapper::type($totals->class, $invoice->kind);
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
                fn (): IssueResult => $operation($totals, $type),
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
                'ARCA did not confirm voucher {number}; keep it for retryIssue()',
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

    private function retryLocked(Invoice $invoice, Preview $totals, int $type, int $unconfirmedNumber): IssueResult
    {
        $nextNumber = $this->gateway->lastNumber($type, $invoice->salesPoint) + 1;
        if ($nextNumber < $unconfirmedNumber) {
            throw new InvalidArgumentException('The unconfirmed number is ahead of the fiscal sequence.');
        }

        $compare = FiscalEvidence::lookupForRetry(...);
        if ($nextNumber > $unconfirmedNumber) {
            try {
                $found = $this->lookup($invoice, $totals, $type, $unconfirmedNumber, $compare);
            } catch (ArcaUnavailable|ConfigurationError|LockTimeout $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new UnconfirmedIssue($unconfirmedNumber, $exception);
            }
            if ($found instanceof IssueResult) {
                return $found;
            }
            if ($found === VoucherLookup::Empty) {
                // ARCA contradicts itself. Retrying cannot settle it, so do not ask for a retry.
                throw new RuntimeException(
                    "ARCA reports voucher {$unconfirmedNumber} as used but cannot find it. Nothing was sent: "
                    . 'reconcile it manually before issuing again.',
                );
            }

            // The earlier request can only land on its own number, which is settled: this is a new issue.
            return $this->issueNumber($invoice, $totals, $type, $nextNumber, FiscalEvidence::lookup(...));
        }

        // The earlier request can still take this number. Recover its CAE after any rejection.
        try {
            return $this->issueNumber($invoice, $totals, $type, $nextNumber, $compare);
        } catch (InvoiceRejected $exception) {
            try {
                $found = $this->lookup($invoice, $totals, $type, $nextNumber, $compare);
            } catch (Throwable $lookupFailure) {
                throw new UnconfirmedIssue($nextNumber, $lookupFailure);
            }
            if ($found instanceof IssueResult) {
                return $found;
            }

            throw $exception;
        }
    }

    /** @param callable(object, array): array $compare */
    private function issueNumber(
        Invoice $invoice,
        Preview $totals,
        int $type,
        int $number,
        callable $compare,
    ): IssueResult {
        if ($number > 99999999) {
            throw new RuntimeException('The fiscal sequence is exhausted. Nothing was sent.');
        }
        $request = $this->request($invoice, $totals, $type, $number);
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
        if ($evidence['status'] === 'rejected') {
            throw new InvoiceRejected($number, $evidence['observations']);
        }

        try {
            $found = $this->lookup($invoice, $totals, $type, $number, $compare);
        } catch (Throwable $exception) {
            throw new UnconfirmedIssue($number, $failure ?? $exception);
        }
        if ($found instanceof IssueResult) {
            return $found;
        }

        throw new UnconfirmedIssue($number, $failure);
    }

    /** @param callable(object, array): array $compare */
    private function lookup(
        Invoice $invoice,
        Preview $totals,
        int $type,
        int $number,
        callable $compare,
    ): IssueResult|VoucherLookup {
        try {
            $found = $this->gateway->lookup($type, $invoice->salesPoint, $number);
        } catch (Throwable $exception) {
            if (Failures::isNotFound($exception)) {
                return VoucherLookup::Empty;
            }

            throw $exception;
        }
        $evidence = $compare($found, $this->request($invoice, $totals, $type, $number));

        return match ($evidence['status']) {
            'authorized' => $this->authorized(
                $invoice,
                $totals,
                $number,
                InvoiceMapper::parseDate($evidence['date']),
                new Authorization($evidence['cae'], InvoiceMapper::parseDate($evidence['expires'])),
                [],
            ),
            'conflict' => VoucherLookup::Other,
            default => throw new RuntimeException("ARCA's voucher could not be compared: {$evidence['reason']}."),
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

    private function request(Invoice $invoice, Preview $totals, int $type, int $number): array
    {
        $detail = InvoiceMapper::detail($invoice, $totals, $this->configuration->cuit);
        $detail['CbteDesde'] = $detail['CbteHasta'] = $number;

        return [
            'FeCAEReq' => [
                'FeCabReq' => ['CantReg' => 1, 'PtoVta' => $invoice->salesPoint, 'CbteTipo' => $type],
                'FeDetReq' => ['FECAEDetRequest' => [$detail]],
            ],
        ];
    }
}
