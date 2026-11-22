<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Credentials;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CredentialsTest extends TestCase
{
    public function testReadsEncryptedPemInMemoryAndUsesTheSameDerFingerprint(): void
    {
        $certificate = file_get_contents($this->directory . '/certificate.crt');
        $key = file_get_contents($this->directory . '/private.key');
        $parsed = openssl_pkey_get_private($key);
        openssl_pkey_export($parsed, $encrypted, 'test-secret');
        $plain = new Credentials(certificate: $certificate, privateKey: $key);
        $secured = new Credentials(certificate: $certificate, privateKey: $encrypted, passphrase: 'test-secret');
        $reformatted = new Credentials("\n" . str_replace("\n", "\r\n", $certificate) . "\n", $key);
        self::assertSame($plain->fingerprint, $secured->fingerprint);
        self::assertSame($plain->fingerprint, $reformatted->fingerprint);
        self::assertSame(openssl_x509_fingerprint($certificate, 'sha256'), $plain->fingerprint);
    }

    public function testRejectsAValidButUnrelatedPrivateKey(): void
    {
        $other = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($other, $otherPem);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');
        new Credentials(file_get_contents($this->directory . '/certificate.crt'), $otherPem);
    }

    #[DataProvider('invalidPem')]
    public function testRejectsMalformedAmbiguousOrFileBasedInputs(string $case): void
    {
        $certificate = file_get_contents($this->directory . '/certificate.crt');
        $key = file_get_contents($this->directory . '/private.key');
        match ($case) {
            'certificate-path' => $certificate = $this->directory . '/certificate.crt',
            'key-path' => $key = 'file://' . $this->directory . '/private.key',
            'multiple-certificates' => $certificate .= $certificate,
            'multiple-keys' => $key .= $key,
            'invalid-certificate' => $certificate = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----",
            'invalid-key' => $key = "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----",
            'public-key' => $key = openssl_pkey_get_details(openssl_pkey_get_private($key))['key'],
        };
        $this->expectException(InvalidArgumentException::class);
        new Credentials($certificate, $key);
    }

    public static function invalidPem(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'certificate-path',
            'key-path',
            'multiple-certificates',
            'multiple-keys',
            'invalid-certificate',
            'invalid-key',
            'public-key',
        ]);
    }

    public function testDebuggingDoesNotRevealPemAndSerializationIsRefused(): void
    {
        $identity = $this->identity();
        ob_start();
        var_dump($identity);
        $debug = ob_get_clean();
        self::assertStringContainsString('[REDACTED]', $debug);
        self::assertStringNotContainsString('BEGIN', $debug);
        self::assertStringContainsString($identity->fingerprint, $debug);
        $this->expectException(\LogicException::class);
        serialize($identity);
    }
}
