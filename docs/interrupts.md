# Interrupts
Interrupts are a way to interrupt the normal flow of a procer program and go back to php code.
Later you can resume the execution of the procer code from the point where it was interrupted.

## Emitting interrupts
To emit an interrupt inside custom functions, you simply need to return `\Karboosx\Procer\Interrupt\Interrupt` object.

```php
use Karboosx\Procer\Interrupt\Interrupt;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, array $arguments): string
    {
        return new Interrupt();
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

## Types of interrupts
There are two types of interrupts: `BEFORE_EXECUTION` and `AFTER_EXECUTION`.
- `BEFORE_EXECUTION` when resuming the execution of the procer code, the code will start executing just before the function that emitted the interrupt was called. Meaning that the function will be called again.
- `AFTER_EXECUTION` when resuming the execution of the procer code, the code will start executing just after the function that emitted the interrupt was called. Meaning that the function will not be called again.

### How to use `BEFORE_EXECUTION` interrupt

Given the following procer code:
```procer
let x be a() + something.
```

and the following custom function:

```php
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Context;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function a(Context $context, array $arguments): int|Interrupt
    {
        if ($context->has('something') === false) {
            return new Interrupt(Interrupt::BEFORE_EXECUTION);
        }
        
        return 1;
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['a']);
    }
}
```

The first execution of the `a` function will return an interrupt of type `BEFORE_EXECUTION` because the variable `something` is not defined.
When resuming the execution of the procer code, the `a` function will be called again and this time it will return `1` because we defined the variable `something` when we resumed the execution of the procer code.

```php
use Karboosx\Procer;

$procer = new Karboosx\Procer([
    new CustomFunctionProvider()
]);

$result = $procer->run('let x be a() + something.');

// At this point we stopped the execution at the `a` function and if we want to resume the execution of the procer code we need to define the variable `something`.

$context = $procer->resume(null, ['something' => 2]);

echo $result->get('x'); // 3
```

## Returning values from interrupts
You can return values from interrupts by passing the value to the constructor of the `Interrupt` object.

```php
use Karboosx\Procer\Interrupt\Interrupt;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, array $arguments): string|Interrupt
    {
        return new Interrupt(Interrupt::AFTER_EXECUTION, 'Hello World!');
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

the `Hello World!` string will be returned when resuming the execution of the procer code.

```
let x be custom_function().
```

so after resuming the execution of the procer code, the value of `x` will be `Hello World!`.

## Returning values from interrupts to php

You can return values from interrupts to php by passing the value as third argument of the constructor of the `Interrupt` object.

```php
use Karboosx\Procer\Interrupt\Interrupt;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, array $arguments): string|Interrupt
    {
        return new Interrupt(Interrupt::AFTER_EXECUTION, 'Hello World!', 'to php');
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

then if you want to get the value of the interrupt in php you can do the following:

```php
use Karboosx\Procer;

$procer = new Karboosx\Procer([
    new CustomFunctionProvider()
]);

$result = $procer->run('let x be custom_function().');
// the script will stop at this point

echo $result->getInterruptData(); // to php

$result = $procer->resume();
// we need to resume the execution of the procer code to get the value of the interrupt

echo $result->get('x'); // Hello World!
```

