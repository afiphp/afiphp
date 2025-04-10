<?php

namespace Afiphp\Webservices;

use Exception;

abstract class AbstractWsAfipWithCredentials extends AbstractWsAfip
{
    protected Credentials $credentials;

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function __construct(
        string $cuit,
        $paths,
        ?string $passphrase = null,
        bool $sandbox = true,
        bool $debug = false
    ) {
        $this->credentials = (new WsAa($cuit, $paths, $passphrase, $sandbox, $debug))
            ->getCredentials($this->getServiceName());

        parent::__construct($cuit, $paths, $passphrase, $sandbox, $debug);
    }

    /**
     * @inheritDoc
     */
    public function execute(string $method, array $params = []): object
    {
        $params['Auth'] = [
            'Token' => $this->credentials->getToken(),
            'Sign' => $this->credentials->getSign(),
            'Cuit' => $this->cuit,
        ];

        return parent::execute($method, $params);
    }
}
