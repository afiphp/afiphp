<?php

declare(strict_types=1);

namespace Afiphp\Storage;

use Afiphp\Internal\Storage\FileJournal;
use Afiphp\Internal\Storage\PrivateFiles;
use InvalidArgumentException;
use RuntimeException;

/**
 * One private, persistent root on a local POSIX filesystem, shared by all issuing workers.
 * Not a distributed lock or an ephemeral cache.
 */
final class FileStorage implements Storage
{
    private string $directory;

    public function __construct(
        string $directory,
        private int $lockTimeout = 120,
    ) {
        if ($lockTimeout < 1) {
            throw new InvalidArgumentException('Lock timeout must be positive.');
        }
        PrivateFiles::ensurePrivateDirectory($directory);
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve storage directory.');
        }
        $this->directory = $resolved;
        PrivateFiles::ensurePrivateDirectory($resolved . '/tickets');
        PrivateFiles::ensurePrivateDirectory($resolved . '/issuance');
    }

    public function exclusively(string $scope, callable $callback): mixed
    {
        $base = $this->directory . '/issuance/' . hash('sha256', $scope);

        return PrivateFiles::withLock($base . '.lock', $this->lockTimeout, static function () use (
            $base,
            $callback,
        ): mixed {
            $journal = new FileJournal($base . '.json');
            try {
                return $callback($journal);
            } finally {
                $journal->close();
            }
        });
    }

    public function readTicket(string $key): ?string
    {
        $filename = $this->ticketPath($key);
        if (!is_file($filename)) {
            return null;
        }
        $ticket = @file_get_contents($filename);
        if ($ticket === false) {
            throw new RuntimeException('Unable to read the authentication ticket.');
        }
        return $ticket;
    }

    public function writeTicket(string $key, string $ticket): void
    {
        PrivateFiles::write($this->ticketPath($key), $ticket);
    }

    public function withTicketLock(string $key, int $timeout, callable $callback): mixed
    {
        return PrivateFiles::withLock($this->ticketPath($key) . '.lock', $timeout, $callback);
    }

    public function writeDiagnostic(string $key, string $xml): void
    {
        PrivateFiles::ensurePrivateDirectory($this->directory . '/diagnostics');
        PrivateFiles::write($this->directory . '/diagnostics/' . hash('sha256', $key) . '.xml', $xml);
    }

    private function ticketPath(string $key): string
    {
        return $this->directory . '/tickets/' . hash('sha256', $key) . '.xml';
    }
}
