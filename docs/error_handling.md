# Error handling

[Documentation index](README.md) · [Steps and recovery](workflows.md)

Procer has two error boundaries: script handlers for explicitly recoverable operation failures, and PHP exception handling for everything that remains unhandled.

## Choose the right boundary

| Event | Script handler? | Host behavior |
| --- | --- | --- |
| `OperationFailure` with an applicable step handler | Yes | Execution transfers to that handler |
| `OperationFailure` without an applicable handler | No | Original exception propagates to PHP |
| Syntax or invalid `next` target | No | Compilation throws `ParserException` or its subclass |
| Missing function/variable/property, invalid arithmetic or loop input | No | Runtime library exception propagates |
| Ordinary provider exception or PHP error | No | Original exception/error propagates |
| `stop`, signal wait, provider `Interrupt` | No failure | Return a paused `Context` |
| Serialization or eager restoration error | No | Error occurs outside script execution |

Library exception classes extend `Karboosx\Procer\Exception\ProcerException`. Providers, getters, and serializers can throw other PHP exceptions or errors; catching `ProcerException` alone does not catch those.

## Recoverable operation failures in Procer

Throw `OperationFailure` only for expected failures that the application permits a script to handle. This provider-method fragment translates a specific application exception:

```php
use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\OperationFailure;

public function calculate(Context $context, int $input): int
{
    try {
        return $this->service->calculate($input);
    } catch (CalculationUnavailable $error) {
        throw new OperationFailure('Calculation unavailable', 1001, $error);
    }
}
```

`CalculationUnavailable` is an application exception, not a Procer class. The complete [getting started example](getting_started.md#add-a-php-operation-and-handle-failure) runs without an external service.

The handler receives `message`, `code`, `step`, `operation`, `line`, and `column`. See [Failure fields](workflows.md#failure-fields) for their exact meaning. The PHP exception and previous exception are not serialized into the diagnostic. A handler never catches its own failure; an outer handler may catch it.

Returning an error-shaped record, false, null, or an exception object does not throw a failure. Existing provider result conventions keep their existing behavior until the provider explicitly adopts this exception contract.

## Exception hierarchy

```text
ProcerException
├── OperationFailure
├── ParserException
│   └── IcParserException
├── RunnerException
│   ├── FunctionNotFoundException
│   ├── ObjectFunctionNotFoundException
│   ├── VariableNotFoundException
│   ├── PropertyNotFoundException
│   └── MaxCyclesException
├── SerializationException
└── DeserializationException
```

All names are in `Karboosx\Procer\Exception`.

## Compilation errors

`ParserException` covers invalid source, duplicates, malformed records/headers, and parser resource limits. Its `getToken()` returns a token when one is available. Line numbers are one-based; positions are zero-based byte offsets.

Tokenizer/parser errors with a token normally include the source line and a caret automatically. End-of-input and some resource errors may have no token or source position. `setCodeLine()` can add source context when a caller constructs a diagnostic outside the ordinary parse path; do not append it repeatedly to an already formatted message.

`IcParserException` is also a `ParserException`. It includes invalid named-loop targets such as `next missing.`; this is an ordinary script error, not necessarily an internal library bug. Parse and compile complete before any provider operation executes.

## Runtime errors

| Exception | Typical cause | Extra information |
| --- | --- | --- |
| `VariableNotFoundException` | Reading an unassigned variable | `getVariableName()` |
| `FunctionNotFoundException` | No procedure/provider supports a call | `getFunctionName()` |
| `ObjectFunctionNotFoundException` | No matching object provider or target is not an object | `getFunctionName()`, `getObjectClass()` |
| `PropertyNotFoundException` | Object has no readable field/getter | `getPropertyName()`, `getObjectClass()` |
| `MaxCyclesException` | Next instruction would exceed the cumulative budget | Inherits runtime token information |
| `RunnerException` | Invalid arithmetic, non-object property access, invalid range/array input, call-depth limit, reentrancy | `getTokenInfo()` |

`getTokenInfo()` may be null. A non-null `TokenInfo` exposes `line`, `linePosition`, and `width`. A non-object `of` target raises plain `RunnerException`, while a missing property on an object raises `PropertyNotFoundException`.

For an object-method call on a non-object value, `getObjectClass()` is an empty string. Methods/getters and provider operations can throw their own original exceptions as well.

## Host-driven retry

An unhandled runtime error leaves the failing instruction and its operands in place. After addressing its cause, the host can call `resume()` to retry it. Already evaluated arguments are preserved; changing a global does not replace them.

This complete example retries a missing-variable read before it has produced an operand:

<!-- verify:php {"output":"15"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Exception\VariableNotFoundException;
use Karboosx\Procer\Procer;

$procer = new Procer();
try {
    $procer->run('return 10 + supplied.');
} catch (VariableNotFoundException $error) {
    $context = $procer->resume(null, ['supplied' => 5]);
    echo $context->getReturnValue(); // 15
}
```

Retries do not undo mutations made before a callback failed. Repeating an external operation may repeat its effects. Retry only when the cause is corrected and the operation's contract permits it. Use a new `run()` when the whole program should be recomputed.

A **handled** `OperationFailure` is different: the abandoned expression and intervening procedure scopes have already been unwound, and control has moved to the handler. Resuming a pause in that handler continues the handler, not the failed body.

## Serialization and restoration errors

`SerializationException` includes unsupported object/resource types, invalid UTF-8/JSON encoding, non-finite floats, cycles, excessive depth, and size limits. Plain `stdClass` records are supported, but their contents and saved handler bindings must be supported too.

`DeserializationException` includes invalid JSON, unsupported snapshot versions, missing fields, invalid instruction/handler state, unknown object IDs, and missing/incompatible inline-object classes. Domain loaders and `fromJson()` can throw their own exceptions.

The exception classes also expose named constructors for integrations that need to report compatible errors:

| Class | Factories |
| --- | --- |
| `SerializationException` | `unsupportedObject`, `unsupportedType`, `jsonEncodeFailed` |
| `DeserializationException` | `invalidJson`, `versionMismatch`, `missingField`, `unknownObjectId`, `classNotFound`, `classNotJsonSerializable`, `corruptStdClass`, `unknownValueType` |

See [Serialization](serialization.md) for value and version rules. Treat snapshot loading separately from runtime recovery.

## Application error policy

At an application boundary, handle compilation, restoration, expected operation failures, and unexpected PHP failures according to your product's needs. Do not make an outer `catch (Throwable)` automatically retry or convert everything to `OperationFailure`.

Use messages suitable for the script author, retain detailed host-side logs where needed, and preserve a previous exception when translating an expected service failure. Failure records are values visible to scripts and saved processes.
