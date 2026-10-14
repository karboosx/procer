# Advanced runtime topics

[Documentation index](README.md) · [PHP API](php_api.md)

This guide explains runtime inspection and the behavior behind the public workflow features. Applications should normally use `Procer` and `Context`, rather than editing bytecode or scope internals.

## InterruptReason

`Context::getInterruptReason()` returns a `Karboosx\Procer\Interrupt\InterruptReason` enum case or null. Always check `isFinished()` as well: a reason describes the last yield/return, while completion says whether instructions remain.

| Case | Meaning |
| --- | --- |
| `STOP` | A `stop.` statement paused execution; resume after it |
| `WAIT_FOR_SIGNAL` | A wait lacks its required signals; resume rechecks it |
| `FUNCTION_REQUEST` | A provider returned either interrupt type; inspect your provider's contract to know whether it will repeat |
| `RETURN` | The main script explicitly returned; execution is complete |
| `WHILE_STOPPING` | A `stopping` loop reached its pause at the end of an iteration |
| null | Ordinary completion, or no interruption has been set while executing |

The enum also defines `FUNCTION_NOT_FOUND`, but the current runner raises `FunctionNotFoundException` for a missing function rather than yielding with that reason.

Host integration fragment; the handler functions are application callbacks:

```php
use Karboosx\Procer\Interrupt\InterruptReason;

if ($context->isFinished()) {
    handleCompleted($context->getReturnValue());
} else {
    match ($context->getInterruptReason()) {
        InterruptReason::WAIT_FOR_SIGNAL => handleWait($context->getWaitForSignalValue()),
        InterruptReason::FUNCTION_REQUEST => handleProviderPause($context->getInterruptData()),
        InterruptReason::STOP, InterruptReason::WHILE_STOPPING => handleScheduledResume(),
        default => throw new \LogicException('Unexpected process state'),
    };
}
```

Before releasing the worker, save the snapshot and any routing information your host needs. The reason, wait names, and extra interrupt data are not all preserved as host metadata in a snapshot. See [Serialization](serialization.md#what-is-saved).

## Debugging compiled bytecode

`printIcCode()` and `printIcExpression()` return a human-readable listing without executing code or replacing an active process:

<!-- verify:php {"output":"record instruction present"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();
$listing = $procer->printIcCode('let result be 1 + 2 as "value".');
echo str_contains($listing, 'BUILD_RECORD') ? 'record instruction present' : 'missing';
```

The listing is diagnostic output, not a stable source-language API. Instruction counts/offsets may change when compilation changes.

| Instruction | Purpose |
| --- | --- |
| `PUSH_VALUE`, `PUSH_VARIABLE`, `PUSH_BUILD_IN` | Put a value on the operand stack |
| `SET_VARIABLE`, `SET_LOCAL_VARIABLE` | Assign a variable or procedure parameter |
| `MATH_OPERATOR`, `INVERT_VALUE` | Apply arithmetic/comparison or boolean inversion |
| `FUNCTION_CALL`, `OBJECT_FUNCTION_CALL` | Invoke a procedure/provider operation |
| `PUSH_FUNCTION_RESULT` | Use a completed call's return value |
| `PUSH_OBJECT_ACCESS` | Read a public property or eligible getter |
| `IF_NOT_JMP`, `JMP` | Implement conditional control flow |
| `STOP`, `WAIT_FOR_SIGNAL`, `WHILE_STOP` | Pause execution |
| `RET` | Return from the current procedure or main script |
| `BUILD_RECORD` | Assemble evaluated field values into a new record |
| `ENTER_STEP`, `LEAVE_STEP` | Manage recovery frames and temporary handler bindings |
| `NEXT_LOOP` | Leave exited steps and jump to a named loop's normal advancement |

## Recovery frames and scopes

Each procedure call has a scope containing variables, operands, return state, and active steps. The global script has a scope too. A step is not a procedure scope; it records where recovery should go and how much pending computation to discard.

When an operational failure is handled, the runner searches from the innermost scope/step outward. A handler already processing a failure is skipped, as is a step without a handler. Intervening procedure scopes and inner steps are unwound, then the receiving handler gets its diagnostic.

`next` is resolved at compile time against enclosing named loops in the same procedure. Its jump performs the target loop's ordinary increment/advance. It also releases handlers being left, while preserving steps surrounding the target loop.

`return` releases the current scope's steps after evaluating its return value. This makes returning a diagnostic possible even though its temporary binding is then restored or removed.

These details explain the public behavior; applications should not construct or alter step-frame arrays to implement their own control flow.

## MathExpressionReflection

`Karboosx\Procer\Debug\MathExpressionReflection` extracts unique variable and function names without executing an expression:

<!-- verify:php {"output":"price,quantity,userlookup"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Debug\MathExpressionReflection;
use Karboosx\Procer\Parser\Parser;

$expression = Parser::default()->parseExpression('price * quantity as "amount", lookup(user) as "owner"');
$reflection = new MathExpressionReflection($expression);
echo implode(',', $reflection->getVariables()); // price,quantity,user
echo implode(',', $reflection->getFunctions()); // lookup
```

Record labels are not variable names and are not included. Property access reports the root object variable; object calls include the receiver variable and method name.

Reflection is useful for editor hints and dependency inspection. It is not a complete authorization system: getters and registered providers can do work not described by a simple list of expression names. Enforce permissions at application boundaries.

`Parser::default()` uses indentation mode. `new Parser(new Tokenizer(), true)` selects `done` mode for scripts. An isolated expression has no block closer, so expression syntax is the same in both modes.

## The stopping keyword

`while stopping condition do` and `until stopping condition do` pause at the end of each completed iteration. The next resume rechecks the condition. This resembles an explicit `stop.` at the end of the body, but reports `WHILE_STOPPING`.

```procer
let remaining be 3.
while stopping remaining > 0 do
    let remaining be remaining - 1.
return remaining.
```

There is a pause after the final iteration too; a subsequent resume evaluates the now-false condition and exits. A `return` or a `next` targeting an outer loop leaves before this end-of-body pause.

## How the VM cycle counter works

`Process::$cycles` is cumulative across resumes and saved snapshots. A cycle counts an instruction that completes, including a handled operational failure transferring control to its handler. An instruction that throws an unhandled exception does not increment the counter on that failed attempt.

`setMaxCycles($n)` is checked before the next instruction. Finishing exactly at the limit succeeds; attempting another instruction throws `MaxCyclesException` before its effects. A zero budget executes nothing. Use `-1` to disable the limit.

The limit is interpreter configuration and is not saved. A new worker must configure an appropriate total budget for the process's existing count. Reusing the same numerical limit on each resume does not grant a fresh allowance.

```php
$cycles = $context->getProcess()->cycles;
```

A source statement usually compiles to several instructions. Record fields, loop conditions, and handler transitions consume cycles too. The budget cannot interrupt one slow provider call; PHP/application timeouts remain necessary. See [Security](security.md).
