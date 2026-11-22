<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Credentials as SigningCredentials;
use Afiphp\Environment;
use Afiphp\Internal\WebServices\WsAa;
use Afiphp\NetworkOptions;
use Afiphp\Storage\FileStorage;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeTransport;

final class WsAaTest extends TestCase
{
    private static array $certificates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCertificate('first');
    }

    private function useCertificate(string $name): void
    {
        if (!isset(self::$certificates[$name])) {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            $csr = openssl_csr_new(['commonName' => 'afiphp-test-' . $name], $key, ['digest_alg' => 'sha256']);
            $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            openssl_pkey_export($key, $privatePem);
            openssl_x509_export($certificate, $certificatePem);
            self::$certificates[$name] = [$certificatePem, $privatePem];
        }
        file_put_contents($this->directory . '/certificate.crt', self::$certificates[$name][0]);
        file_put_contents($this->directory . '/private.key', self::$certificates[$name][1]);
    }

    private function ticket(string $expiration = '+1 hour', string $token = 'cached-token'): string
    {
        $time = (new DateTimeImmutable($expiration))->format(DATE_ATOM);

        return (
            '<loginTicketResponse><header><expirationTime>'
            . $time
            . '</expirationTime></header>'
            . '<credentials><token>'
            . $token
            . '</token><sign>cached-sign</sign></credentials></loginTicketResponse>'
        );
    }

    private function service(
        FakeTransport $transport,
        Environment $environment = Environment::HOMOLOGATION,
        ?NetworkOptions $options = null,
        string $cuit = '20111111112',
    ): WsAa {
        return new class(
            $this->configuration($cuit, $environment, $options ?? new NetworkOptions()),
            $transport,
        ) extends WsAa {
            protected function sign(string $loginRequest): string
            {
                return 'test-cms';
            }
        };
    }

    public function testReusesValidTicketAndStoresItPrivately(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $credentials = $this->service($transport)->ticket('wsfe');
        self::assertSame('cached-token', $credentials->token);
        self::assertTrue($credentials->isValid());
        self::assertNotNull($credentials->expiresAt);
        self::assertSame('cached-token', $this->service($transport)->ticket('wsfe')->token);
        self::assertCount(1, $transport->calls);
        $files = glob($this->directory . '/storage/tickets/*.xml');
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        self::assertSame([], glob($this->directory . '/storage/tickets/*tra-*.xml'));
    }

    public function testCachedTicketsAreEncryptedAndBoundToTheirEntry(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $this->service($transport)->ticket('wsfe');
        $path = fn (string $service): string => (
            $this->directory
            . '/storage/tickets/'
            . hash('sha256', "wsaa-homologation-ta-{$service}-{$this->identity()->fingerprint}")
            . '.xml'
        );
        $sealed = file_get_contents($path('wsfe'));
        self::assertStringNotContainsString('cached-token', $sealed);
        self::assertStringNotContainsString('cached-sign', $sealed);
        self::assertStringNotContainsString('loginTicketResponse', $sealed);

        // Copied under another service, the entry fails authentication: a cache miss, not a ticket.
        file_put_contents($path('wsfex'), $sealed);
        $this->service($transport)->ticket('wsfex');
        self::assertCount(2, $transport->calls);

        // Tampered or plain entries are cache misses as well.
        $entry = json_decode($sealed, true);
        $entry['data'] = base64_encode(strrev(base64_decode($entry['data'])));
        file_put_contents($path('wsfe'), json_encode($entry));
        $this->service($transport)->ticket('wsfe');
        file_put_contents($path('wsfe'), $this->ticket());
        $this->service($transport)->ticket('wsfe');
        self::assertCount(4, $transport->calls);
        $this->service($transport)->ticket('wsfe');
        self::assertCount(4, $transport->calls);
    }

    public function testCacheIsSeparatedByEnvironmentServiceAndCertificate(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $this->service($transport)->ticket('wsfe');
        $this->service($transport, Environment::PRODUCTION)->ticket('wsfe');
        $this->service($transport)->ticket('wsfex');
        $this->useCertificate('second');
        $this->service($transport)->ticket('wsfe');
        self::assertCount(4, $transport->calls);
        self::assertCount(4, glob($this->directory . '/storage/tickets/*.xml'));
    }

    public function testCorruptAndExpiredCachedTicketsAreRefreshed(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $service = $this->service($transport);
        $service->ticket('wsfe');
        $filename = glob($this->directory . '/storage/tickets/*.xml')[0];
        file_put_contents($filename, '<broken>');
        $service->ticket('wsfe');
        file_put_contents($filename, $this->ticket('-30 seconds'));
        $service->ticket('wsfe');
        self::assertCount(3, $transport->calls);
    }

    public function testNearExpiryTicketIsStillReusedWithoutAnotherLogin(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket('+30 seconds')]);
        $service = $this->service($transport);
        $service->ticket('wsfe');
        $service->ticket('wsfe');
        self::assertCount(1, $transport->calls);
    }

    public function testPemLineEndingsDoNotInvalidateAnExistingTicket(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $this->service($transport)->ticket('wsfe');
        $certificate = file_get_contents($this->directory . '/certificate.crt');
        file_put_contents($this->directory . '/certificate.crt', str_replace("\n", "\r\n", $certificate));
        $this->service($transport)->ticket('wsfe');
        self::assertCount(1, $transport->calls);
    }

    public function testSameCertificateSessionIsSharedAcrossRepresentedCuits(): void
    {
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $this->service($transport)->ticket('wsfe');
        $this->service($transport, Environment::HOMOLOGATION, null, '20222222223')->ticket('wsfe');
        self::assertCount(1, $transport->calls);
    }

    public function testRelativeStorageAndEncryptedPemWorkAfterCwdChanges(): void
    {
        $passphrase = 'ephemeral-test-passphrase';
        $key = openssl_pkey_get_private(file_get_contents($this->directory . '/private.key'));
        openssl_pkey_export($key, $encrypted, $passphrase);
        file_put_contents($this->directory . '/private.key', $encrypted);
        $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
        $cwd = getcwd();
        try {
            chdir($this->directory);
            $service = new WsAa(
                $this->configuration(credentials: $this->identity($passphrase), storage: new FileStorage('storage')),
                $transport,
            );
            chdir($cwd);
            self::assertTrue($service->ticket('wsfe')->isValid());
            self::assertCount(1, $transport->calls);
        } finally {
            chdir($cwd);
        }
    }

    public function testWrongPassphraseDoesNotAttemptLoginAndCleansTemporaryFiles(): void
    {
        $key = openssl_pkey_get_private(file_get_contents($this->directory . '/private.key'));
        openssl_pkey_export($key, $encrypted, 'correct-test-passphrase');
        file_put_contents($this->directory . '/private.key', $encrypted);
        $transport = new FakeTransport();
        try {
            $identity = $this->identity('wrong-test-passphrase');
            $service = new WsAa($this->configuration(credentials: $identity), $transport);
            $service->ticket('wsfe');
            self::fail('Expected a signing failure.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame([], $transport->calls);
            self::assertSame([], glob($this->directory . '/storage/tickets/*.xml'));
        }
    }

    public function testComparesExpiryAsAnInstantNotLocalFormattedStrings(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $transport = new FakeTransport(fn () => (object) ['loginCmsReturn' => $this->ticket()]);
            $service = $this->service($transport);
            $service->ticket('wsfe');
            date_default_timezone_set('America/Argentina/Buenos_Aires');
            $service->ticket('wsfe');
            self::assertCount(1, $transport->calls);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testRejectsPathTraversalInServiceNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service(new FakeTransport())->ticket('../wsfe');
    }

    #[DataProvider('invalidTickets')]
    public function testRejectsInvalidNewTicketsWithoutCachingThem(string $xml): void
    {
        $transport = new FakeTransport(static fn () => (object) ['loginCmsReturn' => $xml]);
        try {
            $this->service($transport)->ticket('wsfe');
            self::fail('Expected invalid ticket.');
        } catch (\Exception $exception) {
            self::assertSame([], glob($this->directory . '/storage/tickets/*.xml'));
        }
    }

    public static function invalidTickets(): array
    {
        return [
            ['<broken>'],
            ['<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><loginTicketResponse/>'],
            [
                '<loginTicketResponse><header><expirationTime>2001-01-01T00:00:00Z</expirationTime></header>'
                    . '<credentials><token>x</token><sign>y</sign></credentials></loginTicketResponse>',
            ],
            ['<loginTicketResponse><header><expirationTime>tomorrow</expirationTime></header></loginTicketResponse>'],
            [
                '<loginTicketResponse><header><expirationTime>2099-01-01T00:00:00Z</expirationTime></header>'
                    . '<credentials><token></token><sign>y</sign></credentials></loginTicketResponse>',
            ],
        ];
    }

    public function testSignsAnAttachedCmsWithEphemeralCertificatesAndCleansTemporaryFiles(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new(['commonName' => 'afiphp-test'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($certificate, $certificatePem);
        file_put_contents($this->directory . '/certificate.crt', $certificatePem);
        file_put_contents($this->directory . '/private.key', $privatePem);

        $transport = new FakeTransport(function (string $method, array $params): object {
            self::assertSame('loginCms', $method);
            self::assertNotFalse(base64_decode($params['in0'], true));
            $signed = $this->directory . '/signed.pem';
            $content = $this->directory . '/verified.xml';
            file_put_contents(
                $signed,
                "MIME-Version: 1.0\n"
                    . "Content-Type: application/x-pkcs7-mime; smime-type=signed-data\n"
                    . "Content-Transfer-Encoding: base64\n\n"
                    . chunk_split($params['in0']),
            );
            self::assertTrue(openssl_pkcs7_verify($signed, PKCS7_NOVERIFY, null, [], null, $content));
            self::assertStringContainsString('<service>wsfe</service>', file_get_contents($content));

            return (object) ['loginCmsReturn' => $this->ticket()];
        });
        $identity = $this->identity();
        unlink($this->directory . '/certificate.crt');
        unlink($this->directory . '/private.key');
        $before = glob(sys_get_temp_dir() . '/.afiphp-cms-*');
        $service = new WsAa($this->configuration(credentials: $identity), $transport);
        self::assertTrue($service->ticket('wsfe')->isValid());
        self::assertSame($before, glob(sys_get_temp_dir() . '/.afiphp-cms-*'));
    }

    public function testRejectsFileReferencesInsteadOfTreatingThemAsPemContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SigningCredentials(
            'file://' . $this->directory . '/certificate.crt',
            'file://' . $this->directory . '/private.key',
        );
    }

    public function testConcurrentWorkersShareOneLogin(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is optional; concurrency is covered on Linux with pcntl.');
        }
        $attempts = $this->directory . '/logins';
        $transport = new FakeTransport(function () use ($attempts): object {
            file_put_contents($attempts, "login\n", FILE_APPEND | LOCK_EX);
            usleep(50000);

            return (object) ['loginCmsReturn' => $this->ticket()];
        });
        $service = $this->service($transport);
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $service->ticket('wsfe');
                    exit(0);
                } catch (\Throwable $exception) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame("login\n", file_get_contents($attempts));
    }
}
