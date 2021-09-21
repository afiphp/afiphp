<?php

namespace Afiphp\Webservices;

class Credentials
{
    protected string $sign;
    protected string $token;

    public function __construct(string $token, string $sign)
    {
        $this->sign = $sign;
        $this->token = $token;
    }

    /**
     * Get sign
     */
    public function getSign(): string
    {
        return $this->sign;
    }

    /**
     * Get token
     */
    public function getToken(): string
    {
        return $this->token;
    }
}
