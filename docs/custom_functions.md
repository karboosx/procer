# Custom functions
Procer allows you to define custom functions that can be used in your Procer code.
This is the essential feature that allows you to define business logic using Procer.

## Defining custom functions
To define a custom function, you can simply implement an interface called `Karboosx\Procer\FunctionProviderInterface`.

```php
class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
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

The `supports` method should return `true` if the function is supported by the provider, otherwise it should return `false`.

The `custom_function` method should have a `Context` object as the first argument and an array of arguments as the second argument.

The `Context` object contains the variables that were defined in the Procer code.

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
Custom functions can stop the execution of the business logic by returning a `StopExecution` object.

```php
use Karboosx\Procer\StopExecution;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, array $arguments)
    {
        // do some stuff here
        
        return new StopExecution(StopExecution::AFTER_FUCNTION);
    }
    
    public function supports(string $functionName): bool
    {
        return in_array($functionName, ['custom_function']);
    }
}
```

The `StopExecution` object has two constants that you can use to stop the execution of the business logic:
1. `StopExecution::AFTER_FUCNTION` - Next execution of the Procer script will start processing just after the function is executed.
2. `StopExecution::BEFORE_FUCNTION` - Next execution of the Procer script will process second time the function that returned the `StopExecution` object.

> **Note:** If `StopExecution::BEFORE_FUCNTION` is returned, then next execution of the Procer script will also execute the function that returned the `StopExecution` object.
> 
> In order to process further, you need to return `StopExecution::AFTER_FUCNTION` from the function or regular value.

For example, giving the following Procer code:
```procer
let x be 1.
let output be custom_function().
```

And the following custom function:

```php
use Karboosx\Procer\StopExecution;

class CustomFunctionProvider implements \Karboosx\Procer\FunctionProviderInterface
{
    public function custom_function(Context $context, array $arguments)
    {
        echo "x is " . $context->get('x');
        
        if ($context->get('x') == 1) {
            $context->set('x', 2);
            return new StopExecution(StopExecution::BEFORE_FUCNTION);
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