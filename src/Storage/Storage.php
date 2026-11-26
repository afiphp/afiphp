<?php

declare(strict_types=1);

namespace Afiphp\Storage;

/**
 * Persistent storage for authentication tickets and issuance locks, shared by every worker of an issuer.
 * No expiring leases: each exclusive lock must survive for the whole callback.
 */
interface Storage
{
    public function readTicket(string $key): ?string;

    public function writeTicket(string $key, string $ticket): void;

    /**
     * Serializes WSAA logins. Throw LockTimeout when the lock is not acquired within $timeout seconds.
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withTicketLock(string $key, int $timeout, callable $callback): mixed;

    /**
     * Serializes issuances on one sales point: from reading the last number until ARCA answers.
     * Throw LockTimeout when the lock is not acquired within $timeout seconds; nothing has been sent then.
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withSalesPointLock(string $key, int $timeout, callable $callback): mixed;
}
