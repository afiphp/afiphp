<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;
use SoapFault;

/** Keep transport faults outside WsException, which historically meant ARCA errors. */
class SoapException extends RuntimeException
{
    public function __construct(SoapFault $fault)
    {
        parent::__construct("SOAP Error: ({$fault->faultcode}) {$fault->faultstring}", 0, $fault);
    }

    public function getFaultCode(): string
    {
        /** @var SoapFault $fault */
        $fault = $this->getPrevious();

        return (string) $fault->faultcode;
    }
}
