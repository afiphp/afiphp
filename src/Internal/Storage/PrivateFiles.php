<?php

declare(strict_types=1);

namespace Afiphp\Internal\Storage;

use Afiphp\Exceptions\LockTimeout;
use RuntimeException;

/** @internal Files here can contain fiscal data or authentication tickets. */
final class PrivateFiles
{
    public static function ensurePrivateDirectory(string $directory): void
    {
        $created = [];
        $path = rtrim($directory, DIRECTORY_SEPARATOR);
        while ($path !== '' && !is_dir($path)) {
            $created[] = $path;
            if (dirname($path) === $path) {
                break;
            }
            $path = dirname($path);
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("Directory is not writable: {$directory}");
        }
        clearstatcache(true, $directory);
        if ((fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException(
                'Storage directory must be private (0700); review its permissions before using it.',
            );
        }
        foreach ($created as $path) {
            self::syncDirectory(dirname($path));
        }
    }

    /** Atomic, private and durable: the content and its directory entry are synced before returning. */
    public static function write(string $filename, string $contents): void
    {
        $temporary = @tempnam(dirname($filename), '.afiphp-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create private temporary file.');
        }
        try {
            if (!@chmod($temporary, 0600)) {
                throw new RuntimeException("Unable to restrict file permissions: {$filename}");
            }
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException("Unable to open file: {$filename}");
            }
            try {
                if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException("Unable to write file: {$filename}");
                }
            } finally {
                fclose($handle);
            }
            if (!@rename($temporary, $filename)) {
                throw new RuntimeException("Unable to replace file: {$filename}");
            }
            // A flushed file is not enough: persist the rename's directory entry as well.
            self::syncDirectory(dirname($filename));
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Never rename a lock file: every contender must lock the same inode.
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withLock(string $filename, int $timeout, callable $callback): mixed
    {
        $mask = umask(0077);
        try {
            $handle = @fopen($filename, 'c');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException("Unable to open lock: {$filename}");
        }
        try {
            if (!@chmod($filename, 0600)) {
                throw new RuntimeException("Unable to restrict lock permissions: {$filename}");
            }
            $deadline = microtime(true) + $timeout;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new LockTimeout("Another worker held the lock for more than {$timeout} seconds.");
                }
                usleep(10000);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to open directory: {$directory}");
        }
        try {
            if (!fsync($handle)) {
                throw new RuntimeException("Unable to persist directory: {$directory}");
            }
        } finally {
            fclose($handle);
        }
    }
}
