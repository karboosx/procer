# Procer

[![Tests](https://github.com/karboosx/procer/actions/workflows/tests.yml/badge.svg)](https://github.com/karboosx/procer/actions/workflows/tests.yml)

Procer is a simple and lightweight language designed to describe processes and workflows in a natural and human-readable way. 
It is designed to be as close to natural language as possible, making it easy to read and write.

----

Example code:
```
let shopping_cart be new_shopping_cart(user_account).

let item be product_from_store("apple").

on shopping_cart add(item).
on user_account do checkout.
```
Each `function call` in this example code (`new_shopping_cart`, `product_from_store`, `add`, `checkout`) is actually a function in php land. 
Here you only write the business logic and the implementation is done in php.

Check the [Procer Syntax](docs/syntax.md) for more information.

## Installation

You can install Procer using composer:

```
composer require procer/procer
```

## Usage

```php
use Procer\Procer;

$procer = new Procer();

$result = $procer->run('let a be 1.');

echo $result->get('a'); // 1
```

### Usage with custom functions
In order to use custom functions in Procer, you need to create a class that implements the `FunctionProviderInterface` interface and pass an instance of this class to the `Procer` constructor.

```php
use Procer\Procer;

$procer = new Procer([
   new CustomFunctionProvider()
]);

$result = $procer->run('let x be custom_function("hello world!").');

echo $result->get('x'); // "custom function result with argument: hello world!"
```

The `CustomFunctionProvider` class should look like this:

```php
class CustomFunctionProvider implements \Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, array $arguments): string
    {
        return "custom function result with argument: {$arguments[0]}";
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

> **Note:** The `supports` method should return `true` if the function is supported by the provider, otherwise it should return `false`.

> **Note:** The `custom_function` method should have a `Context` object as the first argument and an array of arguments as the second argument.
> 
> The `Context` object contains the variables that were defined in the Procer code.

## TODO
- [X] If
- [X] Serializer
- [X] Deserializer
- [ ] Deserializer custom objects
- [X] Resuming code
- [ ] Loops
- [ ] Signals
- [ ] Parent scope variable access
- [ ] Documentation
- [ ] Add more examples
- [ ] Add more tests

## Documentation

- [Procer Syntax](docs/syntax.md)
- [Signals](docs/signals.md)
- [Custom Functions](docs/custom_functions.md)
- [Serialization](docs/serialization.md)
- [Error Handling](docs/error_handling.md)
- [Examples](docs/examples.md)

## Guides
- [How to create proper custom functions](docs/guides/custom_functions.md#how-to-create-proper-custom-functions)
- [Good practices](docs/guides/good_practices.md)
- [Naming conventions](docs/guides/naming_conventions.md)

## Going deeper
- [How Procer works](docs/how_it_works.md)
- [Parser and IC](docs/parser_and_ic.md)

## User submitted code safeness
Code submitted by users is **safe** to run as far as the provided functions are safe.

Procer does not allow to run any php code (except for the provided functions) and does not allow to include files, write to files, read from files, create objects, use eval, or have access to global variables.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
