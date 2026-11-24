<?php

declare(strict_types=1);

namespace Afiphp;

use Afiphp\Billing\Receiver;
use Afiphp\Billing\VatCondition;
use Afiphp\Storage\Storage;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class Configuration
{
    public function __construct(
        public string $cuit,
        public VatCondition $vatCondition,
        public Credentials $credentials,
        public Storage $storage,
        public Environment $environment = Environment::HOMOLOGATION,
        public NetworkOptions $network = new NetworkOptions(),
        /** Receives ARCA calls (debug), logins and issuances (info) and problems (warning, error). Never secrets. */
        public LoggerInterface $logger = new NullLogger(),
    ) {
        if (PHP_INT_SIZE < 8) {
            throw new InvalidArgumentException('Exact fiscal arithmetic requires 64-bit PHP.');
        }
        Receiver::validateCuit($cuit);
        if ($vatCondition === VatCondition::CONSUMIDOR_FINAL) {
            throw new InvalidArgumentException('An issuer cannot be a final consumer.');
        }
    }
}
