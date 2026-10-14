# Provider interrupts

[Documentation index](README.md) · [Custom functions](custom_functions.md)

A provider returns `Karboosx\Procer\Interrupt\Interrupt` to yield control to PHP. This is a resumable pause. It does not throw an exception or enter a step's failure handler.

## Constructor and payloads

```php
new Interrupt(InterruptType::AFTER_EXECUTION, $data, $extraData);
```

Import `Interrupt` and `InterruptType` from `Karboosx\Procer\Interrupt`.

| Argument | Purpose |
| --- | --- |
| `signalType` | `AFTER_EXECUTION` (default) or `BEFORE_EXECUTION` |
| `data` | Completed function result for Procer; used by `AFTER_EXECUTION` |
| `extraData` | Information for the host through `Context::getInterruptData()` |

Both payload arguments default to null. `extraData` is not persisted in the process snapshot. Save it alongside the snapshot if the application needs it later.

## AFTER_EXECUTION: preserve a completed result

<!-- verify:php {"output":"readyfalse421"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Context;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\Procer;

final class CheckpointFunctions implements FunctionProviderInterface
{
    public int $calls = 0;
    public function supports(string $functionName): bool { return $functionName === 'checkpoint'; }
    public function checkpoint(Context $context): Interrupt
    {
        $this->calls++;
        return new Interrupt(InterruptType::AFTER_EXECUTION, 42, 'ready');
    }
}

$functions = new CheckpointFunctions();
$procer = new Procer([$functions]);
$context = $procer->run('let result be checkpoint(). return result.');
echo $context->getInterruptData();    // ready
var_export($context->has('result'));  // false: assignment has not executed yet
$context = $procer->resume();
echo $context->getReturnValue();      // 42
echo $functions->calls;              // 1
```

The call has completed and will not run again on resume. The remaining expression and assignment continue using its saved result. Completed results and surrounding operands survive serialization, provided their values are serializable.

## BEFORE_EXECUTION: ask the host to try the call again

The provider has already been invoked when it returns this interrupt. The name describes the resume position, not a guarantee that no PHP code ran.

On resume, Procer re-evaluates that call's argument expressions and invokes it again. Nested calls used as arguments can therefore repeat. Side effects performed before returning the interrupt are not undone.

Provider-method fragment:

```php
use Karboosx\Procer\Context;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;

public function await_value(Context $context): int|Interrupt
{
    if (!$context->has('supplied_value')) {
        return new Interrupt(InterruptType::BEFORE_EXECUTION, null, 'supply a value');
    }
    return $context->get('supplied_value');
}
```

The host can call `$procer->resume(null, ['supplied_value' => 42]);`. The provider must eventually return a normal value or an `AFTER_EXECUTION` interrupt for execution to advance. A repeatedly returned `BEFORE_EXECUTION` interrupt keeps pausing.

## Comparison with failures

| Outcome | Where execution goes | What happens on resume |
| --- | --- | --- |
| `AFTER_EXECUTION` | Return a context to PHP | Continue after the completed call |
| `BEFORE_EXECUTION` | Return a context to PHP | Re-evaluate arguments and call again |
| Handled `OperationFailure` | Jump to the nearest applicable handler | No automatic retry of the failed call |
| Unhandled exception | Throw to PHP | A host retry starts at the failed instruction, preserving evaluated operands |

This distinction matters for operations with external effects. An unhandled exception retry does not have the same argument-reevaluation behavior as `BEFORE_EXECUTION`.

## Steps, objects, and restoration

Plain and object-function providers share interrupt behavior. Active steps and failure-handler variables remain in place during a pause. If the retried operation later throws `OperationFailure`, normal handler routing applies.

A new interpreter needs the appropriate providers and a cycle budget. The snapshot preserves execution operands, steps, and completed values; it does not save provider instances or their private state. See [Serialization](serialization.md).

Use `isFinished()` and `getInterruptReason()` together. A `FUNCTION_REQUEST` can describe either interrupt type, so it does not by itself mean the function will repeat. The advanced `Process::$lastInterruptType` records the most recent provider interrupt type.

Do not recursively run or resume the same active interpreter from a callback. It raises `RunnerException` before replacing the active program. A separate interpreter is supported.
