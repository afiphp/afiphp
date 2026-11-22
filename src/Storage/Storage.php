<?php

declare(strict_types=1);

namespace Afiphp\Storage;

/**
 * Persistent storage shared by every cooperating issuer: authentication tickets and the
 * durable issuance journal, with separate lifecycles.
 * No expiring leases: each exclusive lock must survive for the whole callback, including I/O.
 */
interface Storage
{
    /**
     * Serialize by issuer/environment scope, not by idempotency key.
     * A failed callback must NOT roll back previously saved records.
     * @template T
     * @param callable(IssuanceJournal): T $callback
     * @return T
     */
    public function exclusively(string $scope, callable $callback): mixed;

    public function readTicket(string $key): ?string;

    public function writeTicket(string $key, string $ticket): void;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withTicketLock(string $key, int $timeout, callable $callback): mixed;

    /** Optional, redacted SOAP diagnostics. Never a source of fiscal truth. */
    public function writeDiagnostic(string $key, string $xml): void;
}
