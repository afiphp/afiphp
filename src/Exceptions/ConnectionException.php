<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

/** The HTTP exchange with ARCA failed: unreachable host, timeout or a non-SOAP error page. */
final class ConnectionException extends SoapException implements ServiceUnavailable {}
