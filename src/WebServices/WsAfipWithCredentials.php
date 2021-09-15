<?php

namespace Afiphp\WebServices;

abstract class WsAfipWithCredentials extends WsAfip
{
    protected Credentials $credentials;

    /**
     * @inheritDoc
     */
    public function __construct(
        string $cuit,
        $paths,
        string $passphrase = '',
        bool $isSandbox = true
    ) {
        $this->credentials = (new WsAa($cuit, $paths, $passphrase, $isSandbox))
            ->getCredentials($this->getServiceName());

        parent::__construct($cuit, $paths, $passphrase, $isSandbox);
    }

    /**
     * @inheritDoc 
     */
    protected function execute(string $method, array $params = []): object
    {
        $params['Auth'] = [
            'Token' => $this->credentials->getToken(),
            'Sign' 	=> $this->credentials->getSign(),
            'Cuit' 	=> $this->cuit,
        ];

        return parent::execute($method, $params);
    }
}