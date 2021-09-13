<?php

namespace Afiphp\WebServices\Dtos;

class ServerStatus
{
    protected bool $appIsOk;
    protected bool $dbIsOk;
    protected bool $authIsOk;

    public function __construct(bool $appIsOk, bool $dbIsOk, bool $authIsOk)
    {
        $this->appIsOk = $appIsOk;
        $this->dbIsOk = $dbIsOk;
        $this->authIsOk = $authIsOk;
    }

    public function appIsOk(): bool
    {
        return $this->appIsOk;
    }

    public function dbIsOk(): bool
    {
        return $this->dbIsOk;
    }

    public function authIsOk(): bool
    {
        return $this->authIdOk;
    }
}