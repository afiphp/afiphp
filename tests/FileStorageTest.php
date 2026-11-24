<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Billing\Invoice;
use Afiphp\Billing\Item;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Billing\VatRate;
use Afiphp\Configuration;
use Afiphp\Exceptions\LockTimeout;
use Afiphp\Internal\WebServices\WsAa;
use Afiphp\Internal\WebServices\WsFeV1;
use Afiphp\Storage\FileStorage;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\FakeFiscalGateway;
use Tests\Support\FakeTransport;

final class FileStorageTest extends TestCase
{
    public function testOneRootOrganizesPrivateStorageWithoutWritingCredentials(): void
    {
        $identity = $this->identity();
        unlink($this->directory . '/certificate.crt');
        unlink($this->directory . '/private.key');
        $storage = $this->storage();
        $arca = new Arca(new Configuration(
            cuit: '20111111112',
            vatCondition: VatCondition::RESPONSABLE_INSCRIPTO,
            credentials: $identity,
            storage: $storage,
        ));
        self::assertInstanceOf(Arca::class, $arca);
        self::assertSame(
            ['locks', 'tickets'],
            array_values(array_diff(scandir($this->directory . '/storage'), ['.', '..'])),
        );
        self::assertSame(0700, fileperms($this->directory . '/storage') & 0777);
        self::assertSame(0700, fileperms($this->directory . '/storage/tickets') & 0777);
        self::assertSame(0700, fileperms($this->directory . '/storage/locks') & 0777);
        self::assertSame([], glob($this->directory . '/storage/*/*'));
    }

    public function testTicketAndDiagnosticWithTheSameKeyNeverOverwriteEachOther(): void
    {
        $storage = $this->storage();
        $storage->withTicketLock('shared-key', 1, static function () use ($storage): void {
            $storage->writeTicket('shared-key', '<ticket>session</ticket>');
        });
        $storage->writeDiagnostic('shared-key', '<diagnostic/>');
        self::assertSame('<ticket>session</ticket>', $this->storage()->readTicket('shared-key'));
        self::assertCount(1, glob($this->directory . '/storage/tickets/*.xml'));
        self::assertCount(1, glob($this->directory . '/storage/diagnostics/*.xml'));
        self::assertSame(0600, fileperms(glob($this->directory . '/storage/tickets/*.xml')[0]) & 0777);
        self::assertNull($storage->readTicket('../not-an-existing-key'));
    }

    public function testSalesPointLocksExcludeTheSameKeyOnlyAndAreNotTicketLocks(): void
    {
        $storage = $this->storage();
        $result = $storage->withSalesPointLock('homologation-20111111112-2', 1, function () use ($storage): string {
            // Another key, and the ticket lock with the same key, stay free.
            self::assertSame('other', $storage->withSalesPointLock(
                'homologation-20111111112-3',
                1,
                static fn () => 'other',
            ));
            self::assertSame('ticket', $storage->withTicketLock(
                'homologation-20111111112-2',
                1,
                static fn () => 'ticket',
            ));
            try {
                $this->storage()->withSalesPointLock('homologation-20111111112-2', 1, static fn () => 'twice');
                self::fail('Expected the sales point to be locked.');
            } catch (LockTimeout) {
            }

            return 'done';
        });
        self::assertSame('done', $result);
        self::assertSame('again', $storage->withSalesPointLock(
            'homologation-20111111112-2',
            1,
            static fn () => 'again',
        ));
        self::assertSame(0600, fileperms(glob($this->directory . '/storage/locks/*.lock')[0]) & 0777);
    }

    public function testRefusesAnExistingPublicDirectoryWithoutChangingItsPermissions(): void
    {
        mkdir($this->directory . '/public', 0700);
        chmod($this->directory . '/public', 0755);
        try {
            new FileStorage($this->directory . '/public');
            self::fail('Expected private storage.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('0700', $exception->getMessage());
            self::assertSame(0755, fileperms($this->directory . '/public') & 0777);
        }
    }

    public function testAuthenticationSharesOneStorageWithoutPersistingThePrivateKey(): void
    {
        $identity = $this->identity();
        $storage = $this->storage();
        $login = new FakeTransport(static function (string $method, array $params): object {
            self::assertSame('loginCms', $method);
            self::assertNotFalse(base64_decode($params['in0'], true));
            return (object) [
                'loginCmsReturn' =>
                    '<loginTicketResponse><header><expirationTime>' . (new \DateTimeImmutable('+1 hour'))->format(
                        DATE_ATOM,
                    ) . (
                        '</expirationTime></header><credentials><token>shared-token</token>'
                        . '<sign>shared-sign</sign></credentials></loginTicketResponse>'
                    ),
            ];
        });
        $configuration = $this->configuration(credentials: $identity, storage: $storage);
        $provider = new WsAa($configuration, $login);
        $transport = new FakeTransport(static function (string $method, array $params): object {
            self::assertSame('shared-token', $params['Auth']['Token']);
            return (
                $method === 'FECompUltimoAutorizado'
                    ? (object) ['CbteNro' => 49, 'PtoVta' => 2, 'CbteTipo' => 1]
                    : (object) ['FECAESolicitarResult' => FakeFiscalGateway::approved($params)]
            );
        });
        $arca = new Arca($configuration, new WsFeV1($configuration, $transport, $provider));
        $result = $arca->issue(
            new Invoice(
                2,
                Receiver::business('20111111112', VatCondition::RESPONSABLE_INSCRIPTO),
                [Item::netAmount(15000, VatRate::STANDARD_21)],
                new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires')),
            ),
        );
        self::assertCount(1, $login->calls);
        self::assertCount(2, $transport->calls);
        self::assertCount(1, glob($this->directory . '/storage/tickets/*.xml'));
        $reopened = new WsAa($this->configuration(credentials: $identity), $login);
        self::assertSame('shared-token', $reopened->ticket('wsfe')->token);
        self::assertCount(1, $login->calls);
        $key = file_get_contents($this->directory . '/private.key');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory . '/storage', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                self::assertStringNotContainsString($key, file_get_contents($entry->getPathname()));
            }
        }
    }
}
