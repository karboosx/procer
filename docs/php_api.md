# PHP API reference

[Documentation index](README.md) · [Getting started](getting_started.md)

The interpreter class is `Karboosx\Procer\Procer`. Load Composer's autoloader before using it.

## Procer

| Method | Result and behavior |
| --- | --- |
| `__construct(array $functionProviders = [])` | Register plain/object providers in order |
| `addFunctionProvider($provider): void` | Add a `FunctionProviderInterface` or `ObjectFunctionProviderInterface` instance |
| `run(string $code, array $variables = [], array $signals = []): Context` | Compile and start a new process; variables become globals |
| `runExpression(string $expression, array $variables = [], array $signals = []): mixed` | Start a new expression evaluation, with no final period; return its value if complete |
| `resume(?Process $process = null, array $variables = [], array $signals = []): Context` | Resume the current process or load the supplied process; merge globals and replace signals |
| `useDoneKeyword(bool $useDoneKeyword = true): void` | Set block syntax for future compilations; default is indentation |
| `setMaxCycles(int $maxCycles): void` | Set the total instruction budget; `-1` disables it, which is the default |
| `printIcCode(string $code): string` | Compile a script to readable bytecode without executing or replacing the active process |
| `printIcExpression(string $expression): string` | The equivalent bytecode inspection for one expression |

`Process` above is `Karboosx\Procer\Runner\Process`.

### Lifetime and configuration

- A successful `run()` or `runExpression()` replaces the current process. Use `resume()` to continue existing work.
- Compilation happens before reset. A compilation error does not replace an already loaded process.
- `resume(null, ...)` requires a loaded program. It does not compile source.
- `resume($process, ...)` loads that process's bytecode and state. Block mode no longer matters because the source is already compiled.
- Provider registration, block-mode preference, and the cycle limit belong to the interpreter, not the snapshot. Configure a new interpreter explicitly.
- Completed programs can be resumed without repeating instructions.
- Calling `run`, `runExpression`, or `resume` recursively on the same active interpreter raises `RunnerException`. A separate interpreter can run inside a provider callback.

### Global variables on resume

The variables argument merges into the global scope; it does not replace all variables or overwrite a procedure's local parameter with the same name. It also does not replace expression operands that have already been evaluated.

<!-- verify:php {"output":"2"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();
$context = $procer->run('stop. return value.', ['value' => 1]);
$context = $procer->resume(null, ['value' => 2]);
echo $context->getReturnValue(); // 2
```

To accumulate application events, store them in your application and pass the full relevant signal set on each resume. Procer replaces its signal set on every execution call.

## Context

`Karboosx\Procer\Context` is returned by `run`/`resume` and passed as the first argument to provider methods.

| Method | Meaning |
| --- | --- |
| `get(string $variableName): mixed` | Read current-scope value, then global; return null if absent |
| `has(string $variableName): bool` | Check current/global existence; a defined null value counts as present |
| `getGlobal(string $variableName): mixed` | Read only the global scope; return null if absent |
| `set(string $variableName, mixed $value): void` | Write directly to the current scope |
| `setGlobal(string $variableName, mixed $value): void` | Write directly to the global scope |
| `isSignal(string $signalName): bool` | Check the signal set supplied to the latest execution call |
| `isFinished(): bool` | Check completion; use this before deciding to resume |
| `getReturnValue(): mixed` | The main script's explicit return value; null if it has not returned a value |
| `getInterruptReason(): ?InterruptReason` | Why execution yielded, or null on ordinary completion |
| `getInterruptData(): mixed` | Extra host data from the latest provider interrupt |
| `getWaitForSignalValue(): ?array` | Signal names requested by a pending wait, or null |
| `serialize(): string` | Save the current `Process` as JSON |
| `getProcess(): Process` | Expose mutable VM state for advanced inspection/integration |

`InterruptReason` is `Karboosx\Procer\Interrupt\InterruptReason`. Its active cases are documented in [Advanced topics](in_depth.md#interruptreason).

### Context is a live view

A `Context` refers to its interpreter's runner. It is not an immutable snapshot. After that interpreter resumes or starts another script, an older context reflects the runner's current state. Save serialized state or copy required result data before reusing the interpreter if you need a historical record.

`Context::set()` always writes the current scope. A Procer `let` follows different rules: it updates an existing current-scope binding, otherwise an existing global, otherwise creates a current-scope binding. See [Procedures](syntax.md#procedures).

### Result versus interrupt data

An `Interrupt` has two separate payloads: a function result for Procer (`data`) and host-facing information (`extraData`). `getInterruptData()` reads the latter. After an `AFTER_EXECUTION` pause, the completed function result is saved, but the surrounding assignment may need a resume before its destination is set.

An unfinished `runExpression()` returns null. Prefer `run('return ... .')` for expressions that can pause, so the context exposes completion, persistence, and the final return value consistently.

## Provider and serialization interfaces

| Interface | Contract |
| --- | --- |
| `FunctionProviderInterface` | `supports(string $functionName): bool`; provider methods receive `Context`, then positional arguments |
| `ObjectFunctionProviderInterface` | `supports(object $object, string $functionName): bool`; methods receive `Context`, object, then arguments |
| `Serializer\SerializableObjectInterface` | `getSerializeId(): string` for application lookup on restore |
| `Serializer\DeserializeObjectProviderInterface` | `supports(string $objectId): bool` and `deserialize(string $objectId): mixed` |
| `Serializer\JsonSerializableInterface` | `toJson(): string` and `static fromJson(string $json): self` for inline domain values |

All names in the table are relative to `Karboosx\Procer`. Procer's `JsonSerializableInterface` is distinct from PHP's built-in `JsonSerializable`.

Provider order, argument evaluation, failures, and type validation are covered in [Custom functions](custom_functions.md). Snapshot compatibility and object loading are covered in [Serialization](serialization.md).
