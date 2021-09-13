<?php

namespace Afiphp\WebServices\Contracts;

interface Invoiceable
{
    public function toAfipService(string $serviceName): array;
}