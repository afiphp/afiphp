<?php

declare(strict_types=1);

namespace Tests;

use Afiphp\Arca;
use Afiphp\Configuration;
use Afiphp\Environment;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Internal\Cli\Cli;
use Afiphp\SalesPoint;
use Tests\Support\FakeFiscalGateway;

final class CliTest extends TestCase
{
    /** @return array{int, string, string} Exit code, output and errors. */
    private function cli(array $arguments, ?FakeFiscalGateway $gateway = null, array $environment = []): array
    {
        $output = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        $gateway ??= new FakeFiscalGateway();
        $code = (new Cli(
            $output,
            $errors,
            $environment,
            $this->directory,
            static fn (Configuration $configuration): Arca => new Arca($configuration, $gateway),
        ))->run($arguments);
        rewind($output);
        rewind($errors);

        return [$code, (string) stream_get_contents($output), (string) stream_get_contents($errors)];
    }

    public function testInitWritesAPrivateKeyAndTheCsrArcaExpects(): void
    {
        file_put_contents($this->directory . '/.gitignore', "vendor/\n");
        [$code, $output] = $this->cli(['init', '--cuit', '20111111112', '--name', 'facturador', '--org', 'Mi SRL']);
        self::assertSame(0, $code);
        self::assertSame(0600, fileperms($this->directory . '/arca-homologation.key') & 0777);
        $subject = openssl_csr_get_subject(
            (string) file_get_contents($this->directory . '/arca-homologation.csr'),
            false,
        );
        self::assertSame(
            [
                'countryName' => 'AR',
                'organizationName' => 'Mi SRL',
                'commonName' => 'facturador',
                'serialNumber' => 'CUIT 20111111112',
            ],
            $subject,
        );
        $key = openssl_pkey_get_private((string) file_get_contents($this->directory . '/arca-homologation.key'));
        self::assertSame(2048, openssl_pkey_get_details($key)['bits']);
        self::assertSame("vendor/\narca-*.key\narca-*.crt\n", file_get_contents($this->directory . '/.gitignore'));
        self::assertStringContainsString('WSASS', $output);
        self::assertStringNotContainsString('PRIVATE KEY', $output);

        [$code, , $errors] = $this->cli(['init', '--cuit', '20111111112']);
        self::assertSame(1, $code);
        self::assertStringContainsString('no lo piso', $errors);

        [$code, $output] = $this->cli(['init', '--cuit', '20111111112', '--env', 'production']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Administración de Certificados Digitales', $output);
    }

    public function testInitRefusesInvalidInput(): void
    {
        foreach ([
            ['init'],
            ['init', '--cuit', '20111111111'],
            ['init', '--cuit', '20111111112', '--env', 'x'],
            ['nope'],
        ] as $arguments) {
            [$code, , $errors] = $this->cli($arguments);
            self::assertSame(2, $code, implode(' ', $arguments));
            self::assertStringContainsString('Uso:', $errors);
        }
    }

    public function testCheckWalksEveryLayer(): void
    {
        $gateway = new FakeFiscalGateway();
        $gateway->onSalesPoints = static fn (): array => [new SalesPoint(2, 'CAE', false, null)];
        [$code, $output] = $this->cli(
            [
                'check',
                '--cert',
                $this->directory . '/certificate.crt',
                '--sales-point',
                '2',
                '--storage',
                $this->directory . '/storage',
            ],
            $gateway,
            ['ARCA_CUIT' => '20111111112', 'ARCA_KEY' => $this->directory . '/private.key'],
        );
        self::assertSame(0, $code, $output);
        foreach (['certificado', 'ARCA', 'login', 'puntos de venta', 'punto de venta 2', 'Listo'] as $layer) {
            self::assertStringContainsString($layer, $output);
        }
        self::assertStringContainsString('Vence pronto', $output);
    }

    public function testCheckNamesTheFailingLayer(): void
    {
        $common = ['check', '--cuit', '20111111112', '--storage', $this->directory . '/storage'];
        $files = ['--cert', $this->directory . '/certificate.crt', '--key', $this->directory . '/private.key'];

        [$code, $output] = $this->cli($common);
        self::assertSame(1, $code);
        self::assertStringContainsString('arca-homologation.crt', $output);

        $gateway = new FakeFiscalGateway();
        $gateway->onSalesPoints = static fn () => throw new ConfigurationError(
            'SOAP Error: (ns1:coe.notAuthorized) No autorizado',
        );
        [$code, $output] = $this->cli([...$common, ...$files], $gateway);
        self::assertSame(1, $code);
        self::assertStringContainsString('no está autorizado para wsfe', $output);
        self::assertStringContainsString('WSASS', $output);

        [$code, $output] = $this->cli([...$common, ...$files, '--env', 'production']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Sin un punto de venta', $output);
    }

    public function testCheckReportsArcaDownWhileCheckingTheSalesPoint(): void
    {
        $gateway = new FakeFiscalGateway();
        $calls = 0;
        $gateway->onSalesPoints = static function () use (&$calls): array {
            if (++$calls > 1) {
                throw new ArcaUnavailable('Could not connect to host', false);
            }

            return [new SalesPoint(2, 'CAE', false, null)];
        };
        [$code, $output] = $this->cli([
            'check',
            '--cuit',
            '20111111112',
            '--cert',
            $this->directory . '/certificate.crt',
            '--key',
            $this->directory . '/private.key',
            '--sales-point',
            '2',
            '--storage',
            $this->directory . '/storage',
        ], $gateway);
        self::assertSame(1, $code);
        self::assertStringContainsString('No se pudo contactar a ARCA', $output);
    }

    public function testDiagnosesKnownConfigurationErrors(): void
    {
        foreach ([
            'coe.alreadyAuthenticated' => 'sesión vigente',
            'cms.cert.expired' => 'venció',
            'cms.cert.untrusted' => 'no reconoce',
            'xml.generationTime.invalid' => 'reloj',
            'ARCA Error: (601) CUIT no autorizada' => 'no está autorizada',
        ] as $message => $expected) {
            self::assertStringContainsString($expected, Cli::diagnose($message, Environment::PRODUCTION)[0]);
        }
    }
}
