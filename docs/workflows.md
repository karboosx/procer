# Steps and recovery

[Documentation index](README.md) · [Working PHP example](getting_started.md#add-a-php-operation-and-handle-failure)

Use a named step to group work that shares a recovery action. The application declares an expected operational failure by throwing `Karboosx\Procer\Exception\OperationFailure`. Procer then transfers control to the nearest applicable step handler.

## Basic form

This fragment requires application functions `calculate` and `log`, and an `input` variable:

```procer
step "Calculate the result" do
    let result be calculate(input).
on failure as problem do
    log(message of problem).
    let result be 0.

return result.
```

| Part | Meaning |
| --- | --- |
| `step` | Start a named group of statements |
| `"Calculate the result"` | A literal diagnostic label chosen by the author |
| `do` | Begin the body |
| `on failure as` | Begin the recovery handler |
| `problem` | A temporary variable name chosen by the author |
| `message of problem` | Read a field from the failure diagnostic |

Names are labels, not function names, unique IDs, or matching rules. They can be repeated. The label must be a string literal; it is not an expression.

## What happens on success or failure

1. Enter the step and execute its body in order.
2. If the body succeeds, skip the handler and continue after the step.
3. If an operation throws `OperationFailure`, stop evaluating the failed body and find the nearest active step with an available handler.
4. Discard pending expression operands and unwind any procedure calls between the failure and that handler.
5. Bind the diagnostic to the handler variable and execute the handler.
6. When the handler finishes normally, continue after its step. `return` or `next` can select a different continuation.

Completed ordinary assignments and external operations remain completed. Steps are not transactions, retries, or database savepoints.

## Decide what should happen after failure

| Intended outcome | Handler action |
| --- | --- |
| Continue using a fallback | Assign the fallback, then let the handler finish |
| Skip the failed item | Use `next` with an enclosing loop's name |
| Finish the current procedure or script | Use `return` |
| Wait for a host decision | Use `stop.` or `wait for signal ...` and resume later |
| Undo an application effect | Call an explicit cleanup operation |

A failed assignment does not clear its destination. If a loop previously assigned `result`, a later failed assignment leaves that old result present. Keep dependent operations inside the step, provide a fallback, or leave the iteration explicitly.

## Continue a named loop

This fragment assumes `jobs` is an array and the application provides `calculate` and `log`:

```procer
for each job in jobs do
    step "Calculate one job" do
        let result be calculate(job).
    on failure as problem do
        log(message of problem).
        next job.

    log(result).
```

`next job.` skips the remaining statements in that iteration and advances the loop normally. It can also target a named range, such as `from 1 to 3 as attempt do`.

The rules are lexical:

- The target must name an enclosing `for each` or `from ... as ...` loop in the same procedure.
- If multiple enclosing loops have the same name, the nearest one is selected.
- Targeting an outer named loop exits the intervening loops and steps.
- Steps surrounding the target loop remain active. Exited handlers restore their temporary variables.
- `while`, `until`, and unnamed ranges do not create target names. Their bodies may still use `next` to target an enclosing named loop.
- A variable with the right name is not enough; an enclosing loop with that name must exist. Invalid targets fail during compilation, even in an unreachable branch.

`next` can be used in a normal body as well as a handler. It does not mean “retry this operation” or “start the script again.” A target named `on` is valid: `next on.`. The existing `next on object.` still calls a provider method named `next`.

## Failure fields

The handler receives a plain `stdClass` record:

| Field | Type | Meaning |
| --- | --- | --- |
| `message` | string | The exception's message |
| `code` | integer | The exception's code; usually `0` unless the provider supplies one |
| `step` | string | The innermost active step label at the failure, even when an outer handler catches it |
| `operation` | string | Function/method/property name when available; otherwise the failing instruction's name |
| `line` | integer or null | One-based source line |
| `column` | integer or null | Zero-based byte offset in that line |

Positions are null when bytecode has no source information. The diagnostic contains no PHP exception object, previous exception, or stack trace. Keep host-side logging if those are needed.

## The handler variable is temporary

The step itself creates no variable scope. Its ordinary assignments follow the existing procedure/global rules.

The handler variable is different: it temporarily shadows a binding in the current scope. If that scope already had a variable with the same name, its value is restored on exit. Otherwise the temporary variable is removed. This also applies to `return`, `next`, and unwinding to an outer handler.

Inside a procedure, a handler can shadow a global name without overwriting that global. Calls still follow normal scope lookup: a called procedure sees its own scope and globals, not arbitrary caller-local variables. Pass diagnostics as arguments when a procedure needs them.

To keep the diagnostic, copy it into a different variable inside the handler:

```procer
step "Calculate" do
    let result be calculate(input).
on failure as problem do
    let last_failure be problem.
```

The handler's prior binding is part of a saved process while the handler is active, so that prior value must also be serializable.

## Nested recovery and cleanup

This fragment requires application operations `prepare`, `finish`, `cancel`, and `log`:

```procer
step "Complete the operation" do
    let ticket be prepare(input).
    step "Finish prepared work" do
        finish(ticket).
    on failure as problem do
        cancel(ticket).
        log(message of problem).
on failure as outer_problem do
    log(message of outer_problem).
```

If `finish` fails, the inner handler runs. If `cancel` then throws `OperationFailure`, it propagates to the outer handler. The same handler never catches its own failure, avoiding an automatic recovery loop. A handler may introduce a nested step with its own handler when cleanup itself needs a fallback.

A step without a handler is also valid. It supplies a diagnostic label, while recovery is left to an enclosing step or the host.

There is no language `throw`, `rethrow`, `finally`, or automatic rollback construct. If application code needs to raise another recoverable failure, expose a provider operation that does so. Design cleanup and fallback behavior explicitly.

## What handlers do not catch

Handlers catch `OperationFailure` and its subclasses. They do not catch ordinary PHP exceptions, `TypeError`, missing variables/functions/properties, arithmetic errors, compilation errors, or cycle-limit errors.

Returning `false`, `null`, an exception object, or an error-shaped record is a normal return value. Procer does not infer failure from the value. See [Error handling](error_handling.md) and [Migration](migration.md).

## Pauses and persistence

`stop`, signal waits, and provider `Interrupt` returns pause execution without entering a failure handler. The active steps and temporary binding remain intact. A restored process continues with the same recovery context.

`BEFORE_EXECUTION` retries a provider call and reevaluates its arguments when resumed. `AFTER_EXECUTION` preserves its completed result. Neither is an automatic response to `OperationFailure`.

If no handler catches a failure, it reaches PHP with the failed instruction and operands preserved. A host may retry with `resume()` after addressing the cause. Side effects can repeat; use that path only when retrying the operation is appropriate. A handled failure has already transferred control to its handler and is not automatically retried.

## Block modes and common syntax mistakes

In indentation mode, align `on failure` with its step, and indent both bodies. In `done` mode, use one closer for the whole step:

<!-- verify:procer done -->
```procer
step "Calculate" do
    let result be calculate(input).
on failure as problem do
    let result be 0.
done
```

The complete header is `on failure as name do`. `on failure do cleanup.` keeps its existing object-call meaning: call `cleanup` on an object named `failure`. It does not declare a handler.

Use `nothing.` for an intentionally empty body in indentation mode. A second handler on the same step is invalid. A period finishes an ordinary statement; do not add one after `do` or `done`.
