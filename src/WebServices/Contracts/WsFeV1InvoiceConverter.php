<?php

namespace Afiphp\WebServices\Contracts;

interface WsFeV1InvoiceConverter
{
    /**
     * Convert to WsFEV1
     */
    public function toWsFeV1(): array;
}