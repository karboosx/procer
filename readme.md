![logo](.github/logo.png?raw=true)

# Procer

[![Tests](https://github.com/karboosx/procer/actions/workflows/tests.yml/badge.svg)](https://github.com/karboosx/procer/actions/workflows/tests.yml)
[![License](https://karboosx.net/badge/License-MIT-dark-green.svg)](LICENSE)

Procer is an embeddable workflow language for PHP. Scripts describe the order of work, conditions, records, and recovery steps. Your PHP application provides the operations they call.

Processes can pause, wait for signals, and resume in the same interpreter or from a saved snapshot. Expected application failures can be handled inside a named step.

## Install

Requires PHP 8.2 or later and `ext-ctype`.

```sh
composer require karboosx/procer
```

These docs describe the implementation in this checkout. To try changes before a package release, run the examples from this repository after `composer install`. Existing integrations should also read the [migration guide](docs/migration.md).

## Run a script

Run this PHP file from your Composer project directory:

<!-- verify:php {"output":"36"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();
$procer->setMaxCycles(1000);
$context = $procer->run(<<<'PROCER'
let subtotal be price * quantity.
return subtotal as "amount", "EUR" as "currency".
PROCER, ['price' => 12, 'quantity' => 3]);

$result = $context->getReturnValue(); // stdClass
echo $result->amount;                // 36
```

`let`, `be`, `return`, and `as` are syntax. `subtotal`, `price`, and `quantity` are variable names. The quoted labels name fields on a new record. Commas separate fields; the period ends the statement.

## Describe work and recovery

This script fragment assumes the host supplies `jobs` and registers `calculate` and `log`:

```procer
for each job in jobs do
    step "Perform the calculation" do
        let result be calculate(job).
    on failure as problem do
        log(message of problem).
        next job.
```

The handler runs when an operation throws `OperationFailure`. `next job.` advances the enclosing named loop. Other PHP exceptions and programming errors return to the host. Steps do not automatically retry or undo completed work.

The [getting started guide](docs/getting_started.md) includes the complete PHP provider, a pause/resume example, and snapshot restoration. Names such as `calculate` and `log` are application functions, not built-in services.

## Documentation

The [documentation index](docs/README.md) gives a reading path for script authors and PHP developers.

| Task | Guide |
| --- | --- |
| Install and run complete examples | [Getting started](docs/getting_started.md) |
| Write variables, conditions, loops, and procedures | [Syntax](docs/syntax.md) |
| Construct records from values and existing objects | [Records](docs/records.md) |
| Handle failures and continue loops | [Steps and recovery](docs/workflows.md) |
| Understand values and evaluation order | [Expressions](docs/expressions.md) |
| Register application operations | [Custom functions](docs/custom_functions.md), [Object functions](docs/object_functions.md) |
| Use the public PHP API | [PHP API reference](docs/php_api.md) |
| Pause and persist execution | [Signals](docs/signals.md), [Interrupts](docs/interrupts.md), [Serialization](docs/serialization.md) |
| Diagnose errors or upgrade an integration | [Error handling](docs/error_handling.md), [Migration](docs/migration.md) |
| Inspect internals or configure limits | [Advanced topics](docs/in_depth.md), [Security](docs/security.md) |
| Set up editor highlighting | [Editor setup](syntaxes/INSTALL.md) |

## Embedding safely

Scripts can use the providers and objects you expose. They cannot directly execute PHP, include files, or instantiate arbitrary PHP classes. Native records create plain `stdClass` values. Public property/getter access and registered providers can still perform application work.

Set a cycle budget, authorize operations in providers, and accept saved bytecode only from trusted storage. Procer does not isolate your PHP callbacks. See [Security](docs/security.md).

## Development checks

```sh
composer install
composer test
composer test:stress
composer test:mutations
composer test:docs
```

The [testing guide](docs/testing.md) covers reproducible seeds, fault injection, coverage, and the limits of the evidence.

## License

[MIT](LICENSE).
