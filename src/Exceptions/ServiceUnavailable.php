<?php

declare(strict_types=1);

namespace Afiphp\Exceptions;

use Throwable;

/**
 * ARCA could not process the call: no connection, a timeout, an HTTP error page or an internal
 * error of the web service. It says nothing about the fiscal data, and during an authorization it
 * does not prove that nothing was issued: the result of issue() is still the evidence.
 */
interface ServiceUnavailable extends Throwable {}
