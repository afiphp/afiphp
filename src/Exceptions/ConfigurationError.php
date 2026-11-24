<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;

/**
 * The issuer's setup keeps ARCA from accepting the request: an expired or untrusted certificate,
 * a service not enabled for the CUIT, a skewed clock, a TLS failure or a WSAA ticket held by another
 * storage. Waiting does not solve it. Nothing was issued.
 */
final class ConfigurationError extends RuntimeException {}
