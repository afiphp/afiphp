<?php

namespace Afiphp\WebServices\Contracts;

interface Invoiceable
{
    /**
     * Convert to AFIP service data
     */
    public function toAfipService(string $serviceName): array;
}