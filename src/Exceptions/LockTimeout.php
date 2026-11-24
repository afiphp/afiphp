<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use RuntimeException;

/**
 * Another worker held the storage lock (an issuance on the same sales point, or a login) for longer
 * than the timeout. Nothing was sent to ARCA: repeat the same call.
 */
final class LockTimeout extends RuntimeException {}
