<?php

namespace Afiphp\WebServices\Contracts;

interface AfipTranslatable
{
    /**
     * Get data for AFIP service
     */
    public function getAfipData(string $serviceName): array;
}