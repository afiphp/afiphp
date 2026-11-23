<?php

declare(strict_types=1);

namespace Afiphp;

/**
 * WSFE's own health report (FEDummy). It needs no certificate, so it does not check the login or
 * the CUIT's authorization: an available service can still reject this issuer.
 */
final readonly class ServiceStatus
{
    /** @internal Constructed from ARCA's response. */
    public function __construct(
        public string $appServer,
        public string $dbServer,
        public string $authServer,
    ) {}

    public function isAvailable(): bool
    {
        foreach ([$this->appServer, $this->dbServer, $this->authServer] as $server) {
            if (strtoupper($server) !== 'OK') {
                return false;
            }
        }

        return true;
    }
}
