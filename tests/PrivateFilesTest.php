<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Internal\Storage\PrivateFiles;
use RuntimeException;

final class PrivateFilesTest extends TestCase
{
    public function testLockUsesTheSameInodeAndReleasesAfterException(): void
    {
        $filename = $this->directory . '/test.lock';
        try {
            PrivateFiles::withLock($filename, 1, static function () use ($filename): void {
                $second = fopen($filename, 'c');
                self::assertFalse(flock($second, LOCK_EX | LOCK_NB));
                fclose($second);
                throw new RuntimeException('callback failed');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('callback failed', $exception->getMessage());
        }
        self::assertSame('released', PrivateFiles::withLock($filename, 1, static fn () => 'released'));
    }
}
