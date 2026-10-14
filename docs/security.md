# Security

[Documentation index](README.md) · [PHP API](php_api.md) · [Serialization](serialization.md)

Procer limits language execution, but the PHP capabilities you expose determine what a script can do. Review providers, inspectable objects, and saved-state storage together.

## Limiting the number of cycles

The number of cycles can be limited using `Karboosx\Procer\Procer::setMaxCycles($maxCycles)`:

```php
$procer = new \Karboosx\Procer\Procer();
$procer->setMaxCycles(10000);
```

When the limit is reached a `\Karboosx\Procer\Exception\MaxCyclesException` is thrown.

The limit is checked before the next instruction. Exactly the configured number of instructions may complete; a zero budget executes nothing, including after repeated `resume()` calls. Completed programs can be resumed without executing further instructions.

### What counts as a cycle?

One cycle is one completed **bytecode instruction**. A single line of Procer typically compiles to several instructions, so cycles do not correspond directly to lines or loop iterations. For example:

- `let a be 1.` — 2 cycles (push value + set variable)
- `let a be x + y.` — 4 cycles (push x, push y, add, set variable)

Measure representative programs and select a budget appropriate to your application. This is not a wall-clock, memory, network, or provider-call timeout. A single provider call can block or do expensive work; enforce those limits in PHP and your infrastructure as well. See [cycle accounting](in_depth.md#how-the-vm-cycle-counter-works) for pauses and failures.

The cycle counter accumulates **across `resume()` calls**, so it reflects total work done by the process, not just the current run.

### Unlimited cycles

Set `max_cycles` to `-1` to disable the limit (default):

```php
$procer->setMaxCycles(-1);
```

## User submitted scripts

Parsing has independent resource limits: 1 MiB of source, 100,000 tokens, 128 active parser nesting levels, and a syntax tree depth of 256. Exceeding a limit raises `ParserException`; these limits also apply to `runExpression()`. Procedure execution allows at most 256 scopes, including the global scope, before raising `RunnerException`. Keep a cycle budget as well: a loop can run indefinitely without growing the call stack.

For user-submitted scripts:

- Always allow only trusted function providers.
- Set `setMaxCycles()` to a reasonable value to prevent infinite loops.
- Procer cannot access PHP globals, files, or `eval` — but the functions you expose can. Keep custom function providers side-effect-free where possible.
- Validate inputs and permissions inside providers, including values from native records. A record is ordinary data, not proof that business validation has succeeded.
- Only use `OperationFailure` for failures that scripts may recover from. Do not turn programming errors or critical enforcement failures into ordinary recovery paths unintentionally. A handler does not undo effects already performed.

## Serialized process snapshots

Serialized `Process` data is executable VM state. Protect its integrity and bind it to the correct user or job:

- Store it server-side when possible. If clients carry it, authenticate it with a signature/MAC or authenticated encryption before deserialization; encryption without integrity protection is insufficient.
- Do not resume snapshots that a user can tamper with directly. A modified snapshot may skip waits, change variables, or point execution at different bytecode.
- Deserialization validates the snapshot shape and rejects unknown opcodes, but it is not an authorization boundary.

Snapshots are limited to 4 MiB, with a maximum serialized value depth of 128. Cyclic arrays/objects, non-finite floats, corrupt type prefixes, invalid instruction arguments, and out-of-range pointers are rejected with serialization/deserialization exceptions. This validates structure, not the provenance or intent of bytecode. Application-defined serialization callbacks and function providers remain responsible for their own behavior.

The same trust requirements apply to both snapshot versions. Version 2 includes record instructions and active recovery state; validation does not make a client-supplied handler or instruction sequence authorized. A restored process also needs the intended providers, object loaders, and cycle policy. See [migration](migration.md) before changing workers with pending snapshots.

## Objects exposed to scripts

Only pass objects that are safe for scripts to inspect. The `of` accessor can read public properties and call public zero-required-argument methods/getters. Do not expose objects with side-effecting public methods unless that behavior is intentional.
