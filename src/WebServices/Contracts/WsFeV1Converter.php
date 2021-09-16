<?php

namespace Afiphp\WebServices\Contracts;

interface WsFeV1Converter
{
    /**
     * Convert to invoice request
     */
    public function toWsFeV1InvoiceRequest(): array;
}