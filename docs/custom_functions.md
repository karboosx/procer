# Custom functions

[Documentation index](README.md) · [PHP API](php_api.md)

Registered providers define what an application lets a script do. Procer does not expose arbitrary PHP functions by name. Implement `Karboosx\Procer\FunctionProviderInterface` and register an instance.

## A complete provider

<!-- verify:php {"output":"42"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Context;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Procer;

final class MathFunctions implements FunctionProviderInterface
{
    public function supports(string $functionName): bool
    {
        return $functionName === 'multiply';
    }

    public function multiply(Context $context, int $left, int $right): int
    {
        return $left * $right;
    }
}

$procer = new Procer([new MathFunctions()]);
$context = $procer->run('return multiply(6, 7).');
echo $context->getReturnValue(); // 42
```

The first PHP parameter is always `Context`. Script arguments follow as individual positional parameters, not as one array. A no-argument script call still receives the context.

Provider methods can have normal PHP parameter and return types. PHP handles their arity/type errors; Procer does not turn such errors into recoverable failures automatically. Validate application constraints explicitly.

## Resolution and names

For a plain call, a script-defined procedure with that name takes precedence. Otherwise Procer checks registered plain-function providers in order; the first `supports()` returning true receives the call. A missing provider raises `FunctionNotFoundException`.

Use an explicit name allowlist and a real public method for each supported name. Provider registration can be done in the constructor or with `$procer->addFunctionProvider($provider)`. A provider may coexist with [object-function providers](object_functions.md).

Function identifiers are case-sensitive in the language. Use the same spelling in scripts and `supports()`.

## Return values and argument evaluation

Return ordinary PHP values for use in expressions: scalar values, null, arrays, records, or application objects. `void` produces null. Function statements discard the result; an assignment or expression can use it.

Arguments are passed in their written positions, but the current compiler **evaluates argument expressions right to left**. For `combine(first(), second())`, `second()` executes first. Record field expressions evaluate left to right. When operation order matters, use separate `let` statements rather than nested effectful arguments.

The language has no named-argument `with` form. `as "field"` constructs a record; it does not bind a PHP parameter.

## Accepting records

A native record arrives as `stdClass`. This provider-method fragment validates its input before the application uses it:

```php
use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\OperationFailure;

public function validate_amount(Context $context, \stdClass $request): int
{
    if (!property_exists($request, 'amount') || !is_int($request->amount)) {
        throw new OperationFailure('amount must be an integer', 1002);
    }
    if ($request->amount < 0) {
        throw new OperationFailure('amount must be non-negative', 1003);
    }
    return $request->amount;
}
```

This example treats invalid business input as recoverable. Use your application's contract to decide which failures are expected. Do not silently bypass validation formerly performed by a domain constructor just because the script now builds a native record.

Script fragment for a registered `validate_amount` operation:

```procer
let request be 24 as "amount".
let amount be validate_amount(request).
```

## Recoverable and unexpected failures

Throw `OperationFailure` for expected operational failures that a script may handle. Its constructor follows PHP's exception contract: message, integer code, optional previous exception.

An active [step handler](workflows.md) receives a diagnostic record. Without a handler, the same exception reaches PHP. Other exceptions, including `TypeError`, are propagated without entering the handler. Returning an error-shaped value is just returning data.

Completed assignments and provider side effects are retained on failure. If a host retries an unhandled call, the provider can run again; design side effects and retry policies accordingly.

## Pausing from a provider

Return an `Interrupt` instead of throwing an exception when work should pause:

```php
use Karboosx\Procer\Context;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;

public function checkpoint(Context $context): Interrupt
{
    return new Interrupt(InterruptType::AFTER_EXECUTION, 42, 'checkpoint reached');
}
```

The result `42` belongs to the Procer call; the extra string is available to PHP through `getInterruptData()`. On resume, `AFTER_EXECUTION` keeps the result without calling the provider again. `BEFORE_EXECUTION` instead re-evaluates arguments and calls the provider again. See [Interrupts](interrupts.md).

## Context and application responsibilities

Use the [Context API](php_api.md#context) to inspect variables, write local/global values, and check signals. `set()` writes the current scope; `setGlobal()` writes the root scope. `get()` returns null for an absent variable, whereas an absent variable referenced by Procer source raises an error.

Providers are application code. Authorize access, validate inputs, enforce service timeouts, and decide which failures are safe to expose. A VM cycle budget does not limit time spent inside one PHP callback.

A provider instance is not saved in a process snapshot. Re-register it when restoring. Provider-local memory is also not saved automatically; store required state in serializable process values or application storage.

Calling the same interpreter recursively while it is running is rejected. Use another `Procer` instance if a callback must evaluate an independent script.
