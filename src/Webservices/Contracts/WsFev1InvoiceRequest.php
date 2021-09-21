<?php

namespace Afiphp\Webservices\Contracts;

interface WsFev1InvoiceRequest
{
    /**
     * Convert to invoice request
     */
    public function toWsFev1InvoiceRequest(): array;
}
