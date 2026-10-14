# Adopting records and workflow recovery

[Documentation index](README.md)

The additions extend the existing language: record expressions, named steps with optional failure handlers, and continuation of named loops. Existing positional function calls and object calls remain valid. No named-argument `with` syntax was introduced.

## What can stay as it is

Existing providers do not need to throw `OperationFailure`. Their ordinary return values, custom result objects, and exceptions keep their existing meanings. Existing source can continue to inspect its own result envelope explicitly.

Provider and method names remain application choices. No HTTP client, parser, AI operation, logger, JSON encoder, collection method, or persistence service is registered automatically by these changes.

## Migrate expected failures at the provider boundary

When an operation should participate in step recovery:

1. Return its successful value directly.
2. Throw `OperationFailure` for specific expected operational failures.
3. Let programming errors and unexpected exceptions propagate normally.
4. Put the operation and its dependent work inside a step.
5. Choose a fallback, `next`, or `return` in the handler.

Provider-method fragment; `TemporaryCalculationFailure` represents a specific exception from the application's service:

```php
use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\OperationFailure;

public function calculate(Context $context, int $input): int
{
    try {
        return $this->service->calculate($input);
    } catch (TemporaryCalculationFailure $error) {
        throw new OperationFailure('Calculation temporarily unavailable', 1001, $error);
    }
}
```

Do not wrap every `Throwable` merely to make handlers catch it. Script authors need to distinguish expected failures from missing functions, bad types, configuration problems, and bugs.

If older callers still depend on a result envelope, retain that operation and expose a separately named throwing operation until callers migrate. Procer does not interpret `{ok, value, error}` values automatically.

## Replace record-building helpers selectively

Native records can replace helper calls used solely to assemble fields:

```procer
let request be amount as "amount", currency as "currency".
```

This creates a `stdClass`. It does not perform the validation, normalization, authorization, or persistence that a domain constructor might perform. Either keep that constructor or adapt the receiving provider to validate the record and build the domain object explicitly.

For a single record argument, assign it first or group it: `save((amount as "amount", currency as "currency")).` Field labels are not named parameters.

Arrays and collection operations remain provider responsibilities. A method such as `add` exists only if an appropriate object-function provider supports it.

## Replace manual readiness flags

Use a step to cover the operations that should stop together on failure. Use `next item.` when a failed iteration must skip later work. Steps do not automatically clear variables assigned by previous iterations, and a handled failure normally continues after its step.

Remove flags only after the new continuation has the same intended effect. Keep explicit cleanup for previously completed operations. A cleanup failure can propagate to an outer step or PHP.

## Saved-process compatibility

| Compiled program | Writer format | Reader requirement |
| --- | --- | --- |
| Only existing instructions | Version 1 | Existing compatible reader or current reader |
| Uses records, steps, or `next` | Version 2 | Reader supporting the new instructions and handler state |

The current reader accepts versions 1 and 2. Older readers reject version 2. Upgrade every worker that may resume new snapshots before creating them. Do not edit a snapshot's version number to force compatibility.

A saved process contains its compiled program. Deploying new source does not rewrite already saved bytecode. Coordinate source changes and pending processes in the application.

Providers, cycle limits, signals, and object-loader registrations are not saved interpreter configuration. Recreate them on each worker. The [serialization guide](serialization.md) explains the full boundary.

## Reliability changes to account for

The parser hardening also rejects formerly tolerated malformed input: missing/trailing argument commas, duplicate procedure/parameter names, orphan closers, and inconsistent block indentation. Logical `and`/`or` short-circuit. Range bounds and steps must be valid finite numbers, and procedure arity is enforced.

Unhandled runtime errors preserve the failing instruction for host retry. Handled operational failures transfer to their handler and discard the abandoned computation. Neither route undoes external effects.

See [Testing](testing.md#compatibility-changes) for the wider compatibility history and [Error handling](error_handling.md) for exception contracts.

## Integration checks

- Exercise success and expected failure for every operation you migrate.
- Confirm unexpected errors still reach PHP.
- Verify handler fallbacks and loop continuation do not use stale values.
- Check cleanup failure and repeated external-operation safety.
- Pause inside both bodies and handlers, then restore in a fresh interpreter.
- Validate record field names/types at application boundaries.
- Check old version 1 snapshots and new version 2 snapshots with your actual object providers.

The library's deterministic fixtures test language behavior. Application integrations still need tests for their own services, permissions, persistence, and retry contracts.
