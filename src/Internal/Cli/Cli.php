<?php

declare(strict_types=1);

namespace Afiphp\Internal\Cli;

use Afiphp\Arca;
use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Configuration;
use Afiphp\Credentials;
use Afiphp\Environment;
use Afiphp\Exceptions\ArcaUnavailable;
use Afiphp\Exceptions\ConfigurationError;
use Afiphp\Storage\FileStorage;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * @internal The `afiphp` command: `init` writes a key and a CSR and prints the ARCA steps; `check` tests
 * each layer in order and names the one that fails. Neither issues anything. Never prints a PEM or a ticket.
 */
final class Cli
{
    private const USAGE = <<<'TXT'
        Uso:
          afiphp init  --cuit 20111111112 [--env homologation|production] [--name afiphp] [--org "Mi empresa"] [--dir .]
          afiphp check [--cuit ...] [--cert arca-homologation.crt] [--key arca-homologation.key]
                       [--env homologation|production] [--sales-point 2] [--storage /privado/arca]

        Cada opción de check también se lee de ARCA_CUIT, ARCA_CERT, ARCA_KEY, ARCA_KEY_PASSPHRASE,
        ARCA_ENVIRONMENT, ARCA_SALES_POINT y ARCA_STORAGE. Ninguno de los dos comandos emite comprobantes.

        TXT;

    /** @var Closure(Configuration): Arca */
    private Closure $arcaFactory;

    /**
     * @param resource $output
     * @param resource $errors
     * @param array<string, string> $environment
     * @param (Closure(Configuration): Arca)|null $arcaFactory Testing seam.
     */
    public function __construct(
        private $output,
        private $errors,
        private array $environment = [],
        private string $workingDirectory = '.',
        ?Closure $arcaFactory = null,
    ) {
        $this->arcaFactory = $arcaFactory ?? static fn (Configuration $configuration): Arca => new Arca($configuration);
    }

    /** @param list<string> $arguments Without the program name. Returns the exit code. */
    public function run(array $arguments): int
    {
        $command = array_shift($arguments);
        try {
            $options = self::options($arguments);

            return match ($command) {
                'init' => $this->init($options),
                'check' => $this->check($options),
                null, 'help', '--help', '-h' => $this->usage(0),
                default => throw new InvalidArgumentException("Comando desconocido: {$command}."),
            };
        } catch (InvalidArgumentException $exception) {
            fwrite($this->errors, $exception->getMessage() . PHP_EOL . PHP_EOL);

            return $this->usage(2);
        }
    }

    private function usage(int $code): int
    {
        fwrite($code === 0 ? $this->output : $this->errors, self::USAGE);

        return $code;
    }

    /** @param array<string, string> $options */
    private function init(array $options): int
    {
        $cuit =
            $options['cuit'] ?? $this->environment['ARCA_CUIT'] ?? throw new InvalidArgumentException('Falta --cuit.');
        Receiver::validateCuit($cuit);
        $environment = self::environment($options['env'] ?? 'homologation');
        $name = $options['name'] ?? 'afiphp';
        if (!preg_match('/^[A-Za-z0-9]{1,40}$/D', $name)) {
            throw new InvalidArgumentException(
                '--name admite solo letras y números: es el alias del certificado en ARCA.',
            );
        }
        $organization = $options['org'] ?? $cuit;
        $directory = $options['dir'] ?? $this->workingDirectory;
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new InvalidArgumentException("No se puede escribir en {$directory}.");
        }
        $base = rtrim($directory, '/') . "/arca-{$environment->value}";
        foreach (["{$base}.key", "{$base}.csr"] as $file) {
            if (file_exists($file)) {
                fwrite($this->errors, "Ya existe {$file}: no lo piso. Borralo o elegí otro --dir.\n");

                return 1;
            }
        }

        // The system's openssl.cnf may add default subject fields, such as stateOrProvinceName=Some-State.
        $config = tempnam(sys_get_temp_dir(), '.afiphp-openssl-');
        if ($config === false || file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n") === false) {
            throw new \RuntimeException('No se pudo preparar la configuración de OpenSSL.');
        }
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $options);
            if ($key === false) {
                throw new \RuntimeException('OpenSSL no pudo generar la clave.');
            }
            // The order ARCA's manual uses: C, O, CN, serialNumber.
            $subject = [
                'countryName' => 'AR',
                'organizationName' => $organization,
                'commonName' => $name,
                'serialNumber' => "CUIT {$cuit}",
            ];
            $csr = openssl_csr_new($subject, $key, $options);
            if (
                !$csr instanceof \OpenSSLCertificateSigningRequest
                || !openssl_pkey_export($key, $keyPem, null, $options)
                || !openssl_csr_export($csr, $csrPem)
            ) {
                throw new \RuntimeException('OpenSSL no pudo generar el CSR.');
            }
        } finally {
            unlink($config);
        }
        self::writePrivate("{$base}.key", $keyPem);
        file_put_contents("{$base}.csr", $csrPem);
        $this->ignore($directory);

        $this->line("✓ arca-{$environment->value}.key   clave privada RSA 2048, permisos 0600. No la compartas.");
        $this->line("✓ arca-{$environment->value}.csr   pedido de certificado, CN={$name}, CUIT {$cuit}");
        $this->line('');
        foreach (self::steps($environment, $name, $cuit, "arca-{$environment->value}") as $step) {
            $this->line($step);
        }

        return 0;
    }

    /** @param array<string, string> $options */
    private function check(array $options): int
    {
        $environment = self::environment($options['env'] ?? $this->environment['ARCA_ENVIRONMENT'] ?? 'homologation');
        $cuit =
            $options['cuit'] ?? $this->environment['ARCA_CUIT'] ?? throw new InvalidArgumentException('Falta --cuit.');
        Receiver::validateCuit($cuit);
        $default = rtrim($this->workingDirectory, '/') . "/arca-{$environment->value}";
        $certificateFile = $options['cert'] ?? $this->environment['ARCA_CERT'] ?? "{$default}.crt";
        $keyFile = $options['key'] ?? $this->environment['ARCA_KEY'] ?? "{$default}.key";
        $salesPoint = $options['sales-point'] ?? $this->environment['ARCA_SALES_POINT'] ?? null;
        if ($salesPoint !== null && !preg_match('/^[1-9][0-9]{0,4}$/D', $salesPoint)) {
            throw new InvalidArgumentException('--sales-point debe ser un número de 1 a 99999.');
        }
        $storage =
            $options['storage']
            ?? $this->environment['ARCA_STORAGE']
            ?? sys_get_temp_dir() . "/afiphp-cli-{$environment->value}-{$cuit}";

        $this->line(
            "Verificando la CUIT {$cuit} en "
            . ($environment === Environment::PRODUCTION ? 'producción' : 'homologación')
            . ':',
        );
        // 1. Credentials, locally.
        foreach (['certificado' => $certificateFile, 'clave' => $keyFile] as $label => $file) {
            if (!is_file($file) || !is_readable($file)) {
                return $this->fail(
                    "No se puede leer el {$label} {$file}.",
                    $label === 'certificado'
                        ? 'Guardá el certificado que te dio ARCA con ese nombre, o pasá --cert.'
                        : 'Usá la clave con la que generaste el CSR (afiphp init), o pasá --key.',
                );
            }
        }
        try {
            $credentials = new Credentials(
                (string) file_get_contents($certificateFile),
                (string) file_get_contents($keyFile),
                $this->environment['ARCA_KEY_PASSPHRASE'] ?? '',
            );
        } catch (InvalidArgumentException $exception) {
            return $this->fail(
                'El certificado o la clave no sirven: ' . $exception->getMessage(),
                'Revisá que el certificado sea el bloque PEM completo y que la clave sea la del CSR.',
            );
        }
        $now = new DateTimeImmutable();
        if ($now < $credentials->validFrom) {
            return $this->fail(
                'El certificado todavía no es válido: rige desde el ' . $credentials->validFrom->format('Y-m-d') . '.',
                'Revisá el reloj de esta máquina, o esperá a esa fecha.',
            );
        }
        if (!$credentials->isValidAt($now)) {
            return $this->fail(
                'El certificado venció el ' . $credentials->expiresAt->format('Y-m-d') . '.',
                'Generá un CSR nuevo con afiphp init y pedí otro certificado en ARCA.',
            );
        }
        $days = intdiv($credentials->expiresAt->getTimestamp() - $now->getTimestamp(), 86400);
        $this->ok('certificado', "vigente hasta el {$credentials->expiresAt->format('Y-m-d')} ({$days} días)");
        if ($days < Arca::CERTIFICATE_WARNING_DAYS) {
            $this->line('  ! Vence pronto: renovalo antes de esa fecha.');
        }

        try {
            $arca = ($this->arcaFactory)(
                new Configuration(
                    $cuit,
                    VatCondition::RESPONSABLE_INSCRIPTO, // Only used to choose a class; check issues nothing.
                    $credentials,
                    new FileStorage($storage),
                    $environment,
                ),
            );
            // 2. ARCA itself, without the certificate.
            $status = $arca->status();
            if (!$status->isAvailable()) {
                return $this->fail(
                    "ARCA informa problemas propios (app {$status->appServer}, base {$status->dbServer}, "
                    . "autenticación {$status->authServer}).",
                    'Esperá y volvé a probar: no es tu configuración.',
                );
            }
            $this->ok('ARCA', 'el servicio wsfe funciona');
            // 3. Login and authorization for wsfe.
            $points = $arca->salesPoints();
            $this->ok('login', "el certificado inicia sesión y wsfe está habilitado para la CUIT {$cuit}");
        } catch (ArcaUnavailable $exception) {
            return $this->fail(
                'No se pudo contactar a ARCA: ' . $exception->getMessage(),
                'Revisá red, proxy o firewall; homologación suele caerse fuera de horario.',
            );
        } catch (ConfigurationError $exception) {
            [$diagnosis, $fix] = self::diagnose($exception->getMessage(), $environment);

            return $this->fail($diagnosis, $fix);
        } catch (Throwable $exception) {
            return $this->fail('Falló la verificación: ' . $exception->getMessage(), null);
        }

        // 4. Sales points.
        $active = array_values(array_filter($points, static fn ($point): bool => $point->isActive()));
        $this->ok(
            'puntos de venta',
            $active === []
                ? 'ARCA no informa ninguno para web services'
                : implode(', ', array_map(
                    static fn ($point): string => "{$point->number} ({$point->emissionType})",
                    $active,
                )),
        );
        if ($salesPoint !== null) {
            try {
                $arca->check((int) $salesPoint);
            } catch (ArcaUnavailable $exception) {
                return $this->fail(
                    'No se pudo contactar a ARCA: ' . $exception->getMessage(),
                    'Revisá red, proxy o firewall; homologación suele caerse fuera de horario.',
                );
            } catch (ConfigurationError $exception) {
                return $this->fail(
                    "El punto de venta {$salesPoint} no sirve: " . $exception->getMessage(),
                    'ARCA → Administración de Puntos de Venta y Domicilios → el sistema de web services de tu '
                    . 'condición ("RECE para aplicativo y Web Services" o "Factura Electrónica - Monotributo - Web Services").',
                );
            }
            $this->ok("punto de venta {$salesPoint}", $points === [] ? 'homologación acepta cualquiera' : 'activo');
        } elseif ($active === [] && $environment === Environment::PRODUCTION) {
            return $this->fail(
                'Sin un punto de venta para web services no se puede facturar en producción.',
                'ARCA → Administración de Puntos de Venta y Domicilios → Agregar.',
            );
        }
        $this->line('');
        $this->line('Listo: esta CUIT puede facturar.');

        return 0;
    }

    /** @return array{string, ?string} */
    public static function diagnose(string $message, Environment $environment): array
    {
        $relationship = $environment === Environment::HOMOLOGATION
            ? 'WSASS → "Crear autorización a servicio" → wsfe - Facturación Electrónica, con la CUIT representada.'
            : 'Administrador de Relaciones → Nueva Relación → Webservices → Facturación Electrónica → el computador fiscal.';

        return match (true) {
            str_contains($message, 'coe.notAuthorized') => [
                'El certificado no está autorizado para wsfe.',
                $relationship,
            ],
            str_contains($message, 'coe.alreadyAuthenticated') => [
                'ARCA ya entregó una sesión vigente a este certificado, guardada en otro almacenamiento.',
                'Esperá unos minutos (2 en producción, 10 en homologación) o usá el --storage de tu aplicación.',
            ],
            str_contains($message, 'cms.cert.expired') => [
                'ARCA dice que el certificado venció.',
                'Generá un CSR nuevo con afiphp init y pedí otro certificado.',
            ],
            str_contains($message, 'cms.') => [
                'ARCA no reconoce este certificado o su firma en este ambiente.',
                'Homologación y producción tienen certificados propios: revisá --env, --cert y --key.',
            ],
            str_contains($message, 'xml.generationTime'), str_contains($message, 'xml.expirationTime') => [
                'El reloj de esta máquina difiere del de ARCA.',
                'Sincronizalo (NTP) y volvé a probar.',
            ],
            str_contains($message, '(601)') => [
                'La CUIT no está autorizada para wsfe con este certificado.',
                $relationship,
            ],
            str_contains($message, '(600)') => [
                'ARCA rechazó la sesión.',
                'Volvé a probar; si persiste, revisá el reloj.',
            ],
            str_contains($message, 'TLS') => [
                'Falló la conexión segura con ARCA.',
                'Revisá los certificados raíz del sistema; como último recurso, NetworkOptions(allowLegacyTls: true).',
            ],
            default => ['ARCA rechazó la configuración: ' . $message, null],
        };
    }

    /** @return list<string> */
    private static function steps(Environment $environment, string $alias, string $cuit, string $files): array
    {
        if ($environment === Environment::HOMOLOGATION) {
            return [
                'Ahora en ARCA, para homologación:',
                '  1. Entrá con clave fiscal en https://auth.afip.gob.ar/contribuyente_/login.xhtml',
                '  2. Abrí "WSASS - Autogestión Certificados Homologación". Si no está, adherilo en',
                '     Administrador de Relaciones → Adherir Servicio → ARCA → Servicios Interactivos → WSASS.',
                '  3. "Nuevo Certificado":',
                "       Nombre simbólico del DN:   {$alias}",
                "       Solicitud de certificado:  el contenido de {$files}.csr",
                '     Apretá "Crear DN y Obtener Certificado".',
                '  4. Copiá el certificado, de -----BEGIN CERTIFICATE----- a -----END CERTIFICATE-----,',
                "     y guardalo como {$files}.crt.",
                '  5. "Crear autorización a servicio":',
                "       Nombre simbólico del DN a autorizar:  {$alias}",
                "       CUIT representado:                    {$cuit}",
                '       Servicio al que desea acceder:        wsfe - Facturación Electrónica',
                '',
                "Después: afiphp check --cuit {$cuit}",
            ];
        }

        return [
            'Ahora en ARCA, para producción:',
            '  1. Entrá con clave fiscal en https://auth.afip.gob.ar/contribuyente_/login.xhtml',
            '  2. Abrí "Administración de Certificados Digitales". Si no está, adherilo en',
            '     Administrador de Relaciones → Nueva Relación → Servicios Interactivos.',
            "  3. \"Agregar alias\": alias {$alias}, archivo {$files}.csr.",
            "  4. Descargá el certificado (CRT) y guardalo como {$files}.crt.",
            '  5. Administrador de Relaciones → Nueva Relación:',
            '       Servicio:       Webservices → Facturación Electrónica',
            "       Representante:  el computador fiscal {$alias}",
            '  6. Administración de Puntos de Venta y Domicilios → un punto de venta para web services.',
            '',
            "Después: afiphp check --env production --cuit {$cuit} --sales-point N",
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private static function options(array $arguments): array
    {
        $options = [];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (!preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/sD', $argument, $match)) {
                throw new InvalidArgumentException("Argumento inesperado: {$argument}.");
            }
            $value = $match[2] ?? array_shift($arguments);
            if ($value === null || $value === '') {
                throw new InvalidArgumentException("Falta el valor de --{$match[1]}.");
            }
            $options[$match[1]] = $value;
        }

        return $options;
    }

    private static function environment(string $value): Environment
    {
        return match ($value) {
            'homologation', 'homologacion', 'homologación', 'test' => Environment::HOMOLOGATION,
            'production', 'produccion', 'producción' => Environment::PRODUCTION,
            default => throw new InvalidArgumentException('--env debe ser homologation o production.'),
        };
    }

    private static function writePrivate(string $file, string $contents): void
    {
        $mask = umask(0077);
        try {
            $handle = fopen($file, 'x');
            if ($handle === false || fwrite($handle, $contents) !== strlen($contents)) {
                throw new \RuntimeException("No se pudo escribir {$file}.");
            }
            fclose($handle);
        } finally {
            umask($mask);
        }
        chmod($file, 0600);
    }

    /** Keeps keys and certificates out of an existing repository. */
    private function ignore(string $directory): void
    {
        $file = rtrim($directory, '/') . '/.gitignore';
        if (!is_file($file)) {
            return;
        }
        $current = (string) file_get_contents($file);
        $missing = array_diff(['arca-*.key', 'arca-*.crt'], preg_split('/\R/', $current) ?: []);
        if ($missing !== []) {
            file_put_contents($file, rtrim($current, "\n") . "\n" . implode("\n", $missing) . "\n");
            $this->line('✓ .gitignore            ' . implode(' y ', $missing) . ' agregados');
        }
    }

    private function ok(string $layer, string $detail): void
    {
        $this->line(sprintf('  ✓ %-18s %s', $layer, $detail));
    }

    private function fail(string $diagnosis, ?string $fix): int
    {
        $this->line("  ✗ {$diagnosis}");
        if ($fix !== null) {
            $this->line("    → {$fix}");
        }

        return 1;
    }

    private function line(string $text): void
    {
        fwrite($this->output, $text . PHP_EOL);
    }
}
