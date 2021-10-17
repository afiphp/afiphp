<?php

use Afiphp\Utils\CuitValidator;

it('can validate CUIT', function () {
    expect(CuitValidator::validate('20111111112'))->toBeTrue();
    expect(CuitValidator::validate('20111111111'))->toBeFalse();
    expect(CuitValidator::validate(''))->toBeFalse();
    expect(CuitValidator::validate('2011111111'))->toBeFalse();
});
