<?php

declare(strict_types=1);

namespace Afiphp\Internal\Billing;

use Afiphp\Billing\Authorization;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\InvoiceClass;
use Afiphp\Billing\InvoiceKind;
use Afiphp\Billing\IssueResult;
use Afiphp\Billing\Item;
use Afiphp\Billing\OtherTax;
use Afiphp\Billing\Outcome;
use Afiphp\Billing\ServicePeriod;
use Afiphp\Configuration;
use Afiphp\Exceptions\IdempotencyConflict;
use Afiphp\Exceptions\InvalidResponseException;
use Afiphp\Exceptions\IssuancePersistenceException;
use Afiphp\Exceptions\WsException;
use Afiphp\Internal\WebServices\WsResponse;
use Afiphp\Storage\IssuanceJournal;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** @internal Durable intent -> single submission -> evidence -> explicit reconciliation. */
final class IssuanceCoordinator
{
    private string $scope;

    public function __construct(
        private Configuration $configuration,
        private FiscalGateway $gateway,
    ) {
        $this->scope = 'wsfe:v1:' . $configuration->environment->value . ':' . $configuration->cuit;
    }

    public function issue(Invoice $invoice, ?string $operation): IssueResult
    {
        $operation ??= 'auto:' . bin2hex(random_bytes(16));
        self::validateOperation($operation);
        $totals = InvoiceCalculator::calculate($invoice, $this->configuration->vatCondition);
        $type = InvoiceMapper::type($totals->class, $invoice->kind);
        $detail = InvoiceMapper::detail($invoice, $totals, $this->configuration->cuit);
        $series = $invoice->salesPoint . ':' . $type;
        $draft = [
            'operation' => $operation,
            'issuerCuit' => $this->configuration->cuit,
            'environment' => $this->configuration->environment->value,
            'fingerprint' => hash('sha256', json_encode([
                'issuerCondition' => InvoiceMapper::issuerFingerprintToken($this->configuration->vatCondition),
                'series' => $series,
                'detail' => $detail,
            ], JSON_THROW_ON_ERROR)),
            'series' => $series,
            'class' => $totals->class->value,
            'kind' => $invoice->kind->value,
            'salesPoint' => $invoice->salesPoint,
            'number' => null,
            'date' => $invoice->date->format('Y-m-d'),
            'total' => $totals->total,
            'status' => 'pending',
            'reason' => 'submission_may_have_started',
            'request' => [
                'FeCAEReq' => [
                    'FeCabReq' => ['CantReg' => 1, 'PtoVta' => $invoice->salesPoint, 'CbteTipo' => $type],
                    'FeDetReq' => ['FECAEDetRequest' => [$detail]],
                ],
            ],
            'attempts' => 0,
            'history' => [],
            // The intent, so later notes can be derived from this operation.
            'invoice' => InvoiceCodec::encode($invoice),
        ];

        return $this->withJournal(
            fn (IssuanceJournal $journal, array $records): IssueResult => $this->reserve($journal, $records, $draft),
        );
    }

    /**
     * A note adjusting an authorized operation of this issuer. Receiver, currency, service and sales point
     * come from the original; null items mirror it in full (items and other taxes). Nothing is sent here.
     * @param list<Item>|null $items
     * @param list<OtherTax> $otherTaxes
     */
    public function noteFor(
        string $originalOperation,
        InvoiceKind $kind,
        ?array $items,
        array $otherTaxes,
        DateTimeImmutable $date,
    ): Invoice {
        self::validateOperation($originalOperation);
        $key = hash('sha256', $originalOperation);
        $records = $this->withJournal(static fn (IssuanceJournal $journal, array $records): array => $records);
        $record = $records[$key] ?? throw new InvalidArgumentException(
            'Unknown original operation in this issuer/environment.',
        );
        if ($record['status'] !== 'authorized') {
            throw new InvalidArgumentException('Only an authorized operation can be adjusted by a note.');
        }
        try {
            $original = InvoiceCodec::decode($record['invoice'] ?? throw new InvalidArgumentException('No intent.'));
        } catch (InvalidArgumentException|\TypeError|\ValueError $exception) {
            throw new RuntimeException('The original operation has no usable stored intent.', previous: $exception);
        }
        $service = $original->service;
        if ($service !== null && $service->paymentDue->format('Y-m-d') < $date->format('Y-m-d')) {
            // A note issued after the original due date cannot keep it.
            $service = new ServicePeriod($service->from, $service->to, $date, $service->includesProducts);
        }
        $note = new Invoice(
            $original->salesPoint,
            $original->receiver,
            $items ?? $original->items,
            $date,
            $service,
            $original->currency,
            $kind,
            self::result($record)->invoiceReference(),
            $items === null ? $original->otherTaxes : $otherTaxes,
        );
        $originalTotal = InvoiceMapper::totals(self::detailOf($record), InvoiceClass::from($record['class']))->total;
        $noteTotal = InvoiceCalculator::calculate($note, $this->configuration->vatCondition)->total;
        if ($kind === InvoiceKind::CREDIT_NOTE && $noteTotal > $originalTotal) {
            throw new InvalidArgumentException(
                'A credit note must not exceed the total of its original. Nothing was sent.',
            );
        }

        return $note;
    }

    /** Read-only at ARCA: reconcile a pending operation against its exact reserved number. */
    public function recover(string $operation): IssueResult
    {
        return $this->withPending($operation, fn (
            IssuanceJournal $journal,
            string $key,
            array $record,
        ): IssueResult => self::result($this->reconcile($journal, $key, $record)));
    }

    /** Resubmit the SAME request/number, only after ARCA confirms its absence and the sequence did not move. */
    public function retry(string $operation): IssueResult
    {
        $retry = function (IssuanceJournal $journal, string $key, array $record): IssueResult {
            $record = $this->reconcile($journal, $key, $record);
            if (($record['reason'] ?? null) !== 'not_found') {
                return self::result($record);
            }
            // Never change the request date, fiscal data or number to make a retry fit.
            self::validateDate(self::detailOf($record));
            $last = $this->gateway->lastNumber(self::typeOf($record), $record['salesPoint']);
            if ($last !== ($record['number'] - 1)) {
                $record['reason'] = 'retry_sequence_mismatch';
                $journal->save($key, $record);
                return self::result($record);
            }

            return $this->submit($journal, $key, $record);
        };

        return $this->withPending($operation, $retry);
    }

    private function reserve(IssuanceJournal $journal, array $records, array $record): IssueResult
    {
        $key = hash('sha256', $record['operation']);
        if (isset($records[$key])) {
            $previous = $records[$key];
            if ($previous['fingerprint'] === $record['fingerprint']) {
                // Replays never submit and never implicitly perform recovery.
                return self::result($previous);
            }
            // Only a final outcome that left nothing of ours at ARCA lets the operation carry corrected data.
            if (!in_array($previous['status'], ['rejected', 'conflict'], true)) {
                throw new IdempotencyConflict('This operation already has different fiscal data. Nothing was sent.');
            }
            unset($previous['superseded']);
            $record['superseded'] = [...($records[$key]['superseded'] ?? []), $previous];
        }
        self::validateDate(self::detailOf($record));
        $series = array_filter(
            $records,
            static fn (array $previous): bool => $previous['series'] === $record['series'],
        );
        foreach ($series as $previous) {
            // Only uncertainty blocks. A conflict is final: ARCA already holds another voucher at that number.
            if ($previous['status'] === 'pending') {
                return self::result([
                    ...$record,
                    'status' => Outcome::BLOCKED->value,
                    'reason' => 'unresolved_previous_operation',
                    'blockedBy' => $previous['operation'],
                ]);
            }
            // ARCA (10016) rejects a date earlier than the last authorized voucher of the series.
            if ($previous['status'] === 'authorized' && $previous['date'] > $record['date']) {
                throw new InvalidArgumentException(
                    'Invoice date precedes an authorized voucher of the same series ('
                    . $previous['date']
                    . '). Nothing was sent.',
                );
            }
        }
        $last = $this->gateway->lastNumber(self::typeOf($record), $record['salesPoint']);
        if ($last < 0 || $last >= 99999999) {
            throw new RuntimeException('Invalid or exhausted fiscal sequence. Nothing was sent.');
        }
        foreach ($series as $previous) {
            // A number ARCA is known to hold (ours or a conflicting voucher) must never be reserved again.
            if (in_array($previous['status'], ['authorized', 'conflict'], true) && $previous['number'] > $last) {
                throw new RuntimeException('ARCA sequence is behind a locally recorded voucher. Nothing was sent.');
            }
        }
        $record['number'] = $last + 1;
        $detail = &$record['request']['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
        $detail['CbteDesde'] = $detail['CbteHasta'] = $record['number'];
        unset($detail);
        // A crash from this point must leave a durable unresolved claim.
        $journal->save($key, $record);

        return $this->submit($journal, $key, $record);
    }

    /** Look up the reserved number and persist the evidence. Never submits. */
    private function reconcile(IssuanceJournal $journal, string $key, array $record): array
    {
        unset($record['lookupResponse'], $record['lookupError']);
        try {
            $found = $this->gateway->lookup(self::typeOf($record), $record['salesPoint'], $record['number']);
            $evidence = FiscalEvidence::lookup($found, $record['request']);
            $record['lookupResponse'] = self::snapshot($found);
        } catch (Throwable $exception) {
            $reason = self::isNotFound($exception) ? 'not_found' : 'lookup_failed';
            $evidence = ['status' => 'pending', 'reason' => $reason];
            $record['lookupError'] = $exception::class;
        }
        $record = self::withEvidence($record, 'lookup', $evidence, 'lookupResponse', 'lookupError');
        self::persistResult($journal, $key, $record);

        return $record;
    }

    /**
     * @template T
     * @param callable(IssuanceJournal, array<string, array>): T $callback
     * @return T
     */
    private function withJournal(callable $callback): mixed
    {
        return $this->configuration->storage->exclusively($this->scope, function (IssuanceJournal $journal) use (
            $callback,
        ): mixed {
            $records = $journal->all();
            foreach ($records as $record) {
                if (
                    ($record['issuerCuit'] ?? null) !== $this->configuration->cuit
                    || ($record['environment'] ?? null) !== $this->configuration->environment->value
                ) {
                    throw new RuntimeException(
                        'Issuance store returned data for a different issuer/environment. Nothing was sent.',
                    );
                }
            }

            return $callback($journal, $records);
        });
    }

    /**
     * Only a pending operation reaches the callback; any other recorded outcome is final.
     * @param callable(IssuanceJournal, string, array): IssueResult $callback
     */
    private function withPending(string $operation, callable $callback): IssueResult
    {
        self::validateOperation($operation);
        $key = hash('sha256', $operation);

        return $this->withJournal(static function (IssuanceJournal $journal, array $records) use (
            $key,
            $callback,
        ): IssueResult {
            $record = $records[$key] ?? throw new InvalidArgumentException(
                'Unknown operation in this issuer/environment. Nothing was sent.',
            );

            return $record['status'] === 'pending' ? $callback($journal, $key, $record) : self::result($record);
        });
    }

    private function submit(IssuanceJournal $journal, string $key, array $record): IssueResult
    {
        ++$record['attempts'];
        $record['reason'] = 'submission_may_have_started';
        $record['history'][] = [
            'kind' => 'submission_started',
            'at' => gmdate(DATE_ATOM),
            'attempt' => $record['attempts'],
        ];
        $journal->save($key, $record);
        unset($record['authorizationResponse'], $record['authorizationError']);
        try {
            $response = $this->gateway->authorize($record['request']);
            $evidence = FiscalEvidence::authorization($response, $record['request'], $this->configuration->cuit);
            $record['authorizationResponse'] = self::snapshot($response);
        } catch (Throwable $exception) {
            // Once intent is committed, any failure is conservatively uncertain.
            $evidence = ['status' => 'pending', 'reason' => 'authorization_failed'];
            $record['authorizationError'] = $exception::class;
            if ($exception instanceof WsException || $exception instanceof InvalidResponseException) {
                $response = $exception->getResponse();
                if ($response !== null) {
                    $record['authorizationResponse'] = self::snapshot($response);
                }
            }
        }
        $record = self::withEvidence(
            $record,
            'submission_result',
            $evidence,
            'authorizationResponse',
            'authorizationError',
        );
        if ($record['attempts'] > 1 && $record['status'] === 'rejected') {
            // A retry's rejection (e.g. duplicate number) cannot disprove an earlier
            // timed-out approval, even after a consultation answered not_found.
            $record['status'] = 'pending';
            $record['reason'] = 'retry_rejected_requires_reconciliation';
        }
        // Failure here intentionally leaves the pre-send claim on disk, never a reusable number.
        self::persistResult($journal, $key, $record);
        return self::result($record);
    }

    /** Apply evidence and keep its raw response or error in the attempt history. */
    private static function withEvidence(
        array $record,
        string $kind,
        array $evidence,
        string $responseField,
        string $errorField,
    ): array {
        $record = array_replace($record, $evidence);
        $record['history'][] = [
            'kind' => $kind,
            'at' => gmdate(DATE_ATOM),
            'evidence' => $evidence,
            'response' => $record[$responseField] ?? null,
            'error' => $record[$errorField] ?? null,
        ];
        if ($record['status'] !== 'pending') {
            unset($record['reason']);
        }

        return $record;
    }

    private static function typeOf(array $record): int
    {
        return $record['request']['FeCAEReq']['FeCabReq']['CbteTipo'];
    }

    private static function detailOf(array $record): array
    {
        return $record['request']['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
    }

    private static function persistResult(IssuanceJournal $journal, string $key, array $record): void
    {
        try {
            $journal->save($key, $record);
        } catch (Throwable $exception) {
            throw new IssuancePersistenceException(self::result($record), $exception);
        }
    }

    private static function result(array $record): IssueResult
    {
        return new IssueResult(
            $record['status'] === 'pending' ? Outcome::INDETERMINATE : Outcome::from($record['status']),
            $record['operation'],
            InvoiceClass::from($record['class']),
            InvoiceKind::from($record['kind']),
            $record['salesPoint'],
            $record['number'],
            InvoiceMapper::parseDate($record['date']),
            InvoiceMapper::totals(self::detailOf($record), InvoiceClass::from($record['class'])),
            $record['status'] === 'authorized'
                ? new Authorization($record['cae'], InvoiceMapper::parseDate($record['expires']))
                : null,
            $record['observations'] ?? [],
            $record['reason'] ?? null,
            $record['blockedBy'] ?? null,
            $record['reconciled'] ?? false,
            $record['status'] === 'authorized' ? QrCode::url($record, $record['cae']) : null,
        );
    }

    private static function validateOperation(string $operation): void
    {
        if (
            $operation === ''
            || strlen($operation) > 200
            || trim($operation) !== $operation
            || preg_match('/[\x00-\x1f\x7f]/', $operation)
            || !preg_match('//u', $operation)
        ) {
            throw new InvalidArgumentException(
                'Operation requires a stable, non-empty UTF-8 identifier of at most 200 bytes, '
                . 'without surrounding whitespace or control characters.',
            );
        }
    }

    private static function validateDate(array $detail): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $products = $detail['Concepto'] === 1;
        $from = $today->modify($products ? '-5 days' : '-10 days')->format('Ymd');
        $to = $today->modify($products ? '+5 days' : '+10 days')->format('Ymd');
        if ($products) {
            $to = min($to, $today->modify('last day of this month')->format('Ymd'));
        }
        if ($detail['CbteFch'] < $from || $detail['CbteFch'] > $to) {
            throw new InvalidArgumentException(
                'Invoice date is outside the current fiscal window ('
                . $from
                . ' through '
                . $to
                . '). Nothing was sent.',
            );
        }
    }

    private static function isNotFound(Throwable $exception): bool
    {
        if (!$exception instanceof WsException || $exception->getErrors() === []) {
            return false;
        }
        $response = $exception->getResponse();
        if (
            $response === null
            || isset($response->ResultGet)
            || isset($response->FeDetResp)
            || isset($response->CodAutorizacion)
            || isset($response->CAE)
            || ($response->Resultado ?? null) === 'A'
        ) {
            return false;
        }
        foreach ($exception->getErrors() as $error) {
            if ((string) WsResponse::field($error, 'Code') !== '602') {
                return false;
            }
        }
        return true;
    }

    private static function snapshot(object $response): array
    {
        return json_decode(
            json_encode($response, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
