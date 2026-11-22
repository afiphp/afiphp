<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

/** The operation already exists with different fiscal data; nothing was sent. */
final class IdempotencyConflict extends \LogicException {}
