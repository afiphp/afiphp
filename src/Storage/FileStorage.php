<?php

declare(strict_types=1);

namespace Afiphp\Storage;

use Afiphp\Internal\Storage\PrivateFiles;
use RuntimeException;

/**
 * One private, persistent root on a local POSIX filesystem, shared by all issuing workers.
 */
final class FileStorage implements Storage
{
    private string $directory;

    public function __construct(string $directory)
    {
        PrivateFiles::ensurePrivateDirectory($directory);
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve storage directory.');
        }
        $this->directory = $resolved;
        PrivateFiles::ensurePrivateDirectory($resolved . '/tickets');
        PrivateFiles::ensurePrivateDirectory($resolved . '/locks');
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

    public function withSalesPointLock(string $key, int $timeout, callable $callback): mixed
    {
        return PrivateFiles::withLock(
            $this->directory . '/locks/' . hash('sha256', 'sales-point:' . $key) . '.lock',
            $timeout,
            $callback,
        );
    }

    private function ticketPath(string $key): string
    {
        return $this->directory . '/tickets/' . hash('sha256', $key) . '.xml';
    }
}
