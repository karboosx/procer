# Object functions and property access

[Documentation index](README.md) · [Custom functions](custom_functions.md)

An object function is a registered provider operation whose target is an object stored in a script variable. Procer passes that object to the provider; it does not automatically invoke any arbitrary method named in an `on` call.

## Call forms

These fragments assume a `counter` object and a provider supporting `add` and `read`:

```procer
on counter add(2).
on counter do add(3).
let value be read() on counter.
```

Equivalent postfix forms put the function first:

```procer
add(2) on counter.
let value be read on counter.
```

No-argument calls can omit parentheses. Prefix calls can include an optional readability verb such as `do` or `run`; it has no runtime effect. Use the documented forms to avoid collisions with expression delimiters such as `as`, `to`, and `by`.

The target after `on` is a variable name. For a nested object, read it into a variable first, then call its operation.

## Complete provider example

<!-- verify:php {"output":"5"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Context;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Procer;

final class Counter
{
    public int $value = 0;
}

final class CounterFunctions implements ObjectFunctionProviderInterface
{
    public function supports(object $object, string $functionName): bool
    {
        return $object instanceof Counter && in_array($functionName, ['add', 'read'], true);
    }
    public function add(Context $context, Counter $counter, int $amount): void
    {
        $counter->value += $amount;
    }
    public function read(Context $context, Counter $counter): int
    {
        return $counter->value;
    }
}

$counter = new Counter();
$procer = new Procer([new CounterFunctions()]);
$context = $procer->run('on counter add(2). add(3) on counter. return read on counter.', [
    'counter' => $counter,
]);
echo $context->getReturnValue(); // 5
```

The PHP method receives `Context`, then the object, then the script's positional arguments. Its return value can be used in an expression. Argument expressions follow the same right-to-left evaluation order as plain function arguments.

## Resolution and failure

Providers are checked in registration order. The first object provider whose `supports($object, $name)` returns true handles the call. Match both the class and operation name. A non-object receiver or a missing matching provider raises `ObjectFunctionNotFoundException`.

Object providers can throw `OperationFailure` or return `Interrupt` with the same semantics as [plain providers](custom_functions.md). A handler does not undo mutations the provider already made to the target object.

Plain and object providers can be registered together. They do not share the same `supports` signature, so separate classes are usually easiest to maintain.

## Reading with of is a different operation

`value of counter` reads an object without consulting an object-function provider. Resolution is:

1. Public property with that exact name.
2. Public method with that exact name and no required arguments.
3. Public `getName()` method with no required arguments.
4. Public `isName()` method with no required arguments.
5. Public `hasName()` method with no required arguments.

Getter names use an uppercased first character of the requested name. Methods with optional parameters are eligible because they require no arguments. An uninitialized public typed property raises a runtime error rather than falling through to a getter. Magic `__get`/`__call` are not a substitute for an eligible declared property or method here.

`name of customer of account` traverses from `account` to its `customer`, then to `name`. Native records use the same `of` access.

Public getters can have side effects or throw exceptions. Expose only objects safe for scripts to inspect; a provider allowlist alone does not restrict this access path. See [Security](security.md#objects-exposed-to-scripts).

## Mutation and persistence

Procer has no property-assignment statement. Use registered object operations when scripts need to change domain objects or collections. Such method names and semantics belong to the application.

Object identity is shared during an execution: putting an object in a record does not clone it. To persist a domain object across workers, implement one of the [serialization contracts](serialization.md#supported-values). The `Counter` above is deliberately a simple runtime example and does not implement a persistence contract.
