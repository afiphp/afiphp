<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Billing\VatCondition;
use Afiphp\Configuration;
use Afiphp\Credentials;
use Afiphp\Environment;
use Afiphp\Internal\WebServices\WsFeV1;
use Afiphp\Storage\FileStorage;
use Afiphp\Storage\Storage;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Tests\Support\FakeTicketProvider;
use Tests\Support\FakeTransport;

abstract class TestCase extends PHPUnitTestCase
{
    protected string $directory;
    private static ?array $pem = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/afiphp-test-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        if (self::$pem === null) {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            $csr = openssl_csr_new(['commonName' => 'afiphp-test'], $key, ['digest_alg' => 'sha256']);
            $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            openssl_pkey_export($key, $privatePem);
            openssl_x509_export($certificate, $certificatePem);
            self::$pem = [$certificatePem, $privatePem];
        }
        file_put_contents($this->directory . '/certificate.crt', self::$pem[0]);
        file_put_contents($this->directory . '/private.key', self::$pem[1]);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    protected function identity(string $passphrase = ''): Credentials
    {
        return new Credentials(
            file_get_contents($this->directory . '/certificate.crt'),
            file_get_contents($this->directory . '/private.key'),
            $passphrase,
        );
    }

    protected function storage(): FileStorage
    {
        return new FileStorage($this->directory . '/storage');
    }

    protected function configuration(
        string $cuit = '20111111112',
        Environment $environment = Environment::HOMOLOGATION,
        ?Credentials $credentials = null,
        ?Storage $storage = null,
    ): Configuration {
        return new Configuration(
            $cuit,
            VatCondition::RESPONSABLE_INSCRIPTO,
            $credentials ?? $this->identity(),
            $storage ?? $this->storage(),
            $environment,
        );
    }

    protected function wsfe(FakeTransport $transport, ?FakeTicketProvider $provider = null): WsFeV1
    {
        return new WsFeV1($this->configuration(), $transport, $provider ?? new FakeTicketProvider());
    }

    protected function authorizationResponse(array $numbers, bool $singleton = false): object
    {
        $details = array_map(static fn (int $number): object => (object) [
            'Resultado' => 'A',
            'CbteDesde' => $number,
            'CbteHasta' => $number,
            'CAE' => '71372966559657',
            'CAEFchVto' => '20261124',
        ], $numbers);

        return (object) ['FECAESolicitarResult' => (object) [
            'FeCabResp' => (object) ['CbteTipo' => 1, 'PtoVta' => 2],
            'FeDetResp' => (object) ['FECAEDetResponse' => $singleton ? $details[0] : $details],
        ]];
    }

    /** Raw WSDL fixture, not a public invoice construction API. */
    protected function soapDetail(): array
    {
        return [
            'CbteDesde' => 50,
            'CbteHasta' => 50,
            'CbteFch' => '20261114',
            'Concepto' => 1,
            'DocTipo' => 80,
            'DocNro' => '20111111112',
            'CondicionIVAReceptorId' => 1,
            'ImpTotal' => '189.30',
            'ImpNeto' => '150.00',
            'ImpIVA' => '31.50',
            'ImpTrib' => '7.80',
            'ImpTotConc' => '0.00',
            'ImpOpEx' => '0.00',
            'MonId' => 'PES',
            'MonCotiz' => '1',
            'CanMisMonExt' => 'N',
            'Iva' => [
                ['Id' => 5, 'BaseImp' => '100.00', 'Importe' => '21.00'],
                ['Id' => 4, 'BaseImp' => '50.00', 'Importe' => '5.25'],
            ],
            'Tributos' => [
                [
                    'Id' => 99,
                    'Desc' => 'Otros',
                    'BaseImp' => '150.00',
                    'Alic' => '1',
                    'Importe' => '1.50',
                ],
                [
                    'Id' => 2,
                    'Desc' => 'Otros dos',
                    'BaseImp' => '150.00',
                    'Alic' => '2',
                    'Importe' => '3.00',
                ],
            ],
            'Opcionales' => [['Id' => '27', 'Valor' => 'SCA'], ['Id' => '2101', 'Valor' => '123']],
            'CbtesAsoc' => [
                ['Tipo' => 1, 'PtoVta' => 2, 'Nro' => 49],
                ['Tipo' => 1, 'PtoVta' => 2, 'Nro' => 48],
            ],
        ];
    }
}
