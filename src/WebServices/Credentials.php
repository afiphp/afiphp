<?php

namespace Afiphp\WebServices;

class Credentials
{
    protected string $sign;
    protected string $token;

    public function __construct(string $token, string $sign)
    {
        $this->sign = $sign;
        $this->token = $token;
    }

    public function getSign(): string
    {
        return $this->sign;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}