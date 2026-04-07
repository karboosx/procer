![logo](.github/logo.png?raw=true)

# Procer

[![Tests](https://github.com/karboosx/procer/actions/workflows/tests.yml/badge.svg)](https://github.com/karboosx/procer/actions/workflows/tests.yml)
[![Documentation](https://karboosx.net/badge/Documentation-done-dark-orange.svg)](/docs)
[![License](https://karboosx.net/badge/License-MIT-dark-green.svg)](LICENSE)
[![Composer](https://karboosx.net/badge/Composer-install-dark-blue.svg)](https://packagist.org/packages/karboosx/procer)

Procer is a simple and lightweight language designed to describe processes and workflows in a natural and human-readable way.
The big advantage of Procer is that it functions can halt the execution of the code and wait for a signal to resume the execution.

----

Example code:
```
let shopping_cart be new_shopping_cart(user_account).

let item be product_from_store("apple").

add(item) on shopping_cart.
on user_account do checkout.
```
Each `function call` in this example code (`new_shopping_cart`, `product_from_store`, `add`, `checkout`) is actually a function in php land. 
Here you only write the business logic and the implementation is done in php.

Check the [Procer Syntax](docs/syntax.md) for more information.

## Installation

You can install Procer using composer:

```
composer require karboosx/procer
```

## Usage

```php
use Karboosx\Procer;

$procer = new Karboosx\Procer();

$result = $procer->run('let a be 1.');

echo $result->get('a'); // 1
```

### Usage with custom functions
In order to use custom functions in Procer, you need to create a class that implements the `FunctionProviderInterface` interface and pass an instance of this class to the `Karboosx\Procer` constructor.

```php
use Karboosx\Procer;

$procer = new Karboosx\Procer([
   new CustomFunctionProvider()
]);

$result = $procer->run('let x be custom_function("hello world!").');

echo $result->get('x'); // "custom function result with argument: hello world!"
```

The `CustomFunctionProvider` class should look like this:

```php
class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, string $argument): string
    {
        return "custom function result with argument: {$argument}";
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

> **Note:** The `supports` method should return `true` if the function is supported by the provider, otherwise it should return `false`.

> **Note:** The `custom_function` method receives a `Context` object as its first argument, followed by the function's arguments as individual parameters (not an array).
>
> The `Context` object provides access to the variables defined in the Procer code.

Check the [Custom Functions documentation](docs/custom_functions.md) for more information.

## Evaluation expression

If you want to evaluate just an expression, you can use the `runExpression` method of the `Karboosx\Procer` class.

```php
use Karboosx\Procer;

$procer = new Procer();

$result = $procer->runExpression('1 + 2 * 3');

echo $result; // 7
```

Check out the [Expression documentation](docs/expressions.md) for more information.

## Pausing and resuming execution

You can pause the execution of the Procer code and resume it later by `resume` method.

Good way to stop the execution is to use the `wait for signal` statement.

```php
use Karboosx\Procer;

$procer = new Procer();

$result = $procer->run(<<<CODE
let a be 1.
wait for signal test_signal.
let a be 2.
CODE);

echo $result->get('a'); // 1

$result = $procer->resume(null, [], ['test_signal']);

echo $result->get('a'); // 2
```

That way you can pause the execution of the script at one point. Wait for user input, or for some event to happen, and then resume the execution of the script.

Check out the [Signals documentation](docs/signals.md) for more information.  
Also, check out the [Serialization documentation](docs/serialization.md) for more information how to serialize and unserialize the script.
## Documentation

### Language
- [Procer Syntax](docs/syntax.md) — variables, loops, conditions, procedures, `stop`, signals
- [Expressions](docs/expressions.md) — operators, precedence, string concatenation, comparisons

### PHP integration
- [Custom Functions](docs/custom_functions.md) — `FunctionProviderInterface`, `Context` API
- [Object Functions](docs/object_functions.md) — `ObjectFunctionProviderInterface`
- [Signals](docs/signals.md) — `wait for signal`, `wait for all signals`, signal statefulness
- [Interrupts](docs/interrupts.md) — pausing from inside a function provider
- [Serialization](docs/serialization.md) — persist and resume a paused process
- [Error Handling](docs/error_handling.md) — exception types and how to catch them
- [Security](docs/security.md) — cycle limits, user-submitted scripts

### Advanced
- [In-Depth](docs/in_depth.md) — `InterruptReason`, bytecode debugging, `MathExpressionReflection`, `stopping` loops, cycle counter

## User submitted code safeness
Code submitted by users is **safe** to run as far as the provided functions are safe.

Procer does not allow to run any php code (except for the provided functions) and does not allow to include files, write to files, read from files, create objects, use eval, or have access to global variables.

## License

This project is open-sourced software licensed under the MIT License. Please see [License File](LICENSE) for more information.
