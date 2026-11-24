<?php

declare(strict_types=1);

namespace Afiphp;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/** Validated signing identity. PEM content is read in memory; the private key is never written to disk. */
final readonly class Credentials
{
    private OpenSSLCertificate $certificate;
    private OpenSSLAsymmetricKey $privateKey;
    private string $ticketKey;
    public string $fingerprint;
    public DateTimeImmutable $validFrom;
    public DateTimeImmutable $expiresAt;

    public function __construct(
        #[\SensitiveParameter]
        string $certificate,
        #[\SensitiveParameter]
        string $privateKey,
        #[\SensitiveParameter]
        string $passphrase = '',
    ) {
        // OpenSSL also accepts file:// references. Credentials explicitly accepts PEM content only.
        if (!preg_match(
            '~\A\s*-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+/=\r\n]+-----END CERTIFICATE-----\s*\z~D',
            $certificate,
        )) {
            throw new InvalidArgumentException('Certificate must contain exactly one PEM certificate, not a filename.');
        }
        $singleKey = substr_count($privateKey, '-----BEGIN ') === 1 && substr_count($privateKey, '-----END ') === 1;
        $keyPattern =
            '~\A\s*-----BEGIN (PRIVATE KEY|ENCRYPTED PRIVATE KEY|RSA PRIVATE KEY|EC PRIVATE KEY)'
            . '-----\s+[A-Za-z0-9+/=\r\n :,-]+-----END \1-----\s*\z~D';
        if (!$singleKey || !preg_match($keyPattern, $privateKey)) {
            throw new InvalidArgumentException('Private key must contain a PEM private key, not a filename.');
        }
        $parsedCertificate = @openssl_x509_read($certificate);
        $parsedKey = @openssl_pkey_get_private($privateKey, $passphrase);
        if ($parsedCertificate === false || $parsedKey === false) {
            throw new InvalidArgumentException(
                'Unable to read certificate or private key. Check PEM content and passphrase.',
            );
        }
        if (!@openssl_x509_check_private_key($parsedCertificate, $parsedKey)) {
            throw new InvalidArgumentException('The private key does not match the certificate.');
        }
        $fingerprint = openssl_x509_fingerprint($parsedCertificate, 'sha256');
        if ($fingerprint === false) {
            throw new InvalidArgumentException('Unable to fingerprint the certificate.');
        }

        $info = openssl_x509_parse($parsedCertificate);
        if (
            !is_array($info)
            || !is_int($info['validFrom_time_t'] ?? null)
            || !is_int($info['validTo_time_t'] ?? null)
        ) {
            throw new InvalidArgumentException('Unable to read the certificate validity.');
        }

        if (!openssl_pkey_export($parsedKey, $exported)) {
            throw new InvalidArgumentException('Unable to derive the ticket encryption key.');
        }

        $this->certificate = $parsedCertificate;
        $this->privateKey = $parsedKey;
        $this->fingerprint = $fingerprint;
        $this->validFrom = new DateTimeImmutable('@' . $info['validFrom_time_t']);
        $this->expiresAt = new DateTimeImmutable('@' . $info['validTo_time_t']);
        // Only the holder of the private key can read cached WSAA tickets.
        $this->ticketKey = hash_hkdf('sha256', $exported, 32, 'afiphp:wsaa-ticket:v1');
    }

    /** Whether the certificate is valid at $at (now by default). ARCA rejects it outside that window. */
    public function isValidAt(?DateTimeImmutable $at = null): bool
    {
        $at ??= new DateTimeImmutable();

        return $at >= $this->validFrom && $at < $this->expiresAt;
    }

    /** @internal AES-256-GCM key for cached WSAA tickets, derived from the private key. */
    public function ticketKey(): string
    {
        return $this->ticketKey;
    }

    /** @internal Attached CMS for WSAA. Only the request/signature use temporary files, never the key. */
    public function sign(#[\SensitiveParameter] string $content): string
    {
        $files = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $file = tempnam(sys_get_temp_dir(), '.afiphp-cms-');
                if ($file === false) {
                    throw new RuntimeException('Unable to create a CMS temporary file.');
                }
                $files[] = $file;
                if (!chmod($file, 0600)) {
                    throw new RuntimeException('Unable to restrict CMS temporary file permissions.');
                }
            }
            [$input, $output] = $files;
            if (
                file_put_contents($input, $content) !== strlen($content)
                || !@openssl_pkcs7_sign($input, $output, $this->certificate, $this->privateKey, [], 0)
            ) {
                throw new RuntimeException('Unable to generate the authentication signature.');
            }
            $signed = file_get_contents($output);
            $parts = preg_split('/\r?\n\r?\n/', $signed === false ? '' : $signed, 2);
            $signature = preg_replace('/\s+/', '', $parts[1] ?? '');
            if ($signature === null || $signature === '' || base64_decode($signature, true) === false) {
                throw new RuntimeException('Invalid CMS signature output.');
            }

            return $signature;
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function __debugInfo(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
            'privateKey' => '[REDACTED]',
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Signing credentials must not be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Signing credentials must not be unserialized.');
    }
}
