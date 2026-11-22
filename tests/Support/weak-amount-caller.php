<?php

// Intentionally no strict_types: callers must not silently coerce floats or strings to cents.
return static fn (mixed $value): \Afiphp\Billing\Item => \Afiphp\Billing\Item::amount($value);
