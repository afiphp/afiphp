# Webservices AFIP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/afiphp/afiphp.svg?style=flat-square)](https://packagist.org/packages/afiphp/afiphp)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/afiphp/afiphp/Tests?label=tests)](https://github.com/afiphp/afiphp/actions?query=workflow%3ATests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/workflow/status/afiphp/afiphp/Check%20&%20fix%20styling?label=code%20style)](https://github.com/afiphp/afiphp/actions?query=workflow%3A"Check+%26+fix+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/afiphp/afiphp.svg?style=flat-square)](https://packagist.org/packages/afiphp/afiphp)

Afiphp allows you to operate with web services regarding AFIP, mainly related to electronic invoicing.

## Installation

You can install the package via composer:

```bash
composer require afiphp/afiphp
```

## Usage

```php
use Afiphp\Webservices\WsFev1;
use Afiphp\Entities\Customer;
use Afiphp\Entities\Invoice;
use Afiphp\Enums\InvoiceType;
use Afiphp\Enums\IdentityDocumentType;

$wsfe = new WsFev1('20111111112', __DIR__ . '/resources');
$invoice = Invoice::createProductInvoice(
        InvoiceType::FACTURA_A,
        2, // sales point
        150, // taxed amount
        0, // untaxed amount
        0, // exempt amount
        new Customer(IdentityDocumentType::CUIT, '20111111112')
    )
    ->addTax(
        5, // code
        150, // taxable base
        31.5 // amount
    );
$cae = $wsfe->createCaeFromInvoice($invoice);
echo $cae->number;
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
