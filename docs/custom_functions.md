# Custom functions
Procer allows you to define custom functions that can be used in your Procer code.
This is the essential feature that allows you to define business logic using Procer.

## Defining custom functions
To define a custom function, you can simply implement an interface called `Karboosx\Procer\FunctionProviderInterface`.

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

The `supports` method should return `true` if the function is supported by the provider, otherwise it should return `false`.

The `custom_function` method receives the `Context` object as its first argument, followed by the function's arguments as individual parameters (not an array).

The `Context` object provides access to the variables defined in the Procer code.

### Context API

| Method | Description |
|---|---|
| `$context->get(string $name)` | Read a variable by name. Returns `null` if not set. |
| `$context->has(string $name)` | Check if a variable exists (returns `true` even if its value is `null`). |
| `$context->set(string $name, mixed $value)` | Write a variable into the current scope. |
| `$context->setGlobal(string $name, mixed $value)` | Write a variable into the global scope (visible from any procedure). |
| `$context->isSignal(string $name)` | Check whether a signal was passed to this execution. |
| `$context->isFinished()` | Check whether the script has finished executing. |

Setting variables from PHP is useful when combined with `Interrupt` — you can write data back before resuming:

```php
public function fetch_user(Context $context, int $id): Interrupt
{
    $context->set('user', $this->userRepository->find($id));
    return new Interrupt(InterruptType::AFTER_EXECUTION, $context->get('user'));
}
```

## Using custom functions

In order to use custom functions in Procer, you need to pass an instance of the class that implements the `FunctionProviderInterface` interface to the `Karboosx\Procer` constructor.

```php
use Karboosx\Procer;

$procer = new Karboosx\Procer([
    new CustomFunctionProvider()
]);

$result = $procer->run('let x be custom_function("hello world!").');

echo $result->get('x'); // "custom function result with argument: hello world!"
```

## Stopping the execution of the business logic from custom functions

Custom functions can pause the execution of the business logic by returning an `Interrupt` object.

```php
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context): Interrupt
    {
        // do some stuff here
        
        return new Interrupt(InterruptType::AFTER_EXECUTION);
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

The `Interrupt` constructor accepts an `InterruptType` enum value:

- `InterruptType::AFTER_EXECUTION` — when resuming, execution continues just **after** the function call. The function is not called again.
- `InterruptType::BEFORE_EXECUTION` — when resuming, the function is **called again** from scratch.

> **Note:** If `InterruptType::BEFORE_EXECUTION` is used, the function will be called again on the next resume.
> To proceed past it, return `InterruptType::AFTER_EXECUTION` or a normal value on that second call.

For example, given the following Procer code:
```procer
let x be 1.
let output be custom_function().
```

And the following custom function:

```php
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context): string|Interrupt
    {
        echo "x is " . $context->get('x') . "\n";
        
        if ($context->get('x') == 1) {
            $context->set('x', 2);
            return new Interrupt(InterruptType::BEFORE_EXECUTION);
        }
        
        return "x is " . $context->get('x');
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

The output will be:
```
x is 1
x is 2
```

and the value of `output` will be `x is 2`.