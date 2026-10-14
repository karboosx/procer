# Security

## Limiting the number of cycles

The number of cycles can be limited using `Karboosx\Procer::setMaxCycles($maxCycles)`:

```php
$procer = new Karboosx\Procer();
$procer->setMaxCycles(10000);
```

When the limit is reached a `\Karboosx\Procer\Exception\MaxCyclesException` is thrown.

The limit is checked before the next instruction. Exactly the configured number of instructions may complete; a zero budget executes nothing, including after repeated `resume()` calls. Completed programs can be resumed without executing further instructions.

### What counts as a cycle?

One cycle = one **bytecode instruction** executed by the VM. A single line of Procer typically compiles to several instructions, so cycles are not a 1:1 match with lines or loop iterations. As a rough guide:

- `let a be 1.` — 2 cycles (push value + set variable)
- `let a be x + y.` — 4 cycles (push x, push y, add, set variable)
- A `from 1 to 100` loop body with 3 instructions — roughly 500+ cycles total

Set the limit conservatively for user-submitted scripts. A value of `50 000` – `500 000` is a reasonable starting point for typical workflows; tune it down for untrusted input.

The cycle counter accumulates **across `resume()` calls**, so it reflects total work done by the process, not just the current run.

### Unlimited cycles

Set `max_cycles` to `-1` to disable the limit (default):

```php
$procer->setMaxCycles(-1);
```

## User submitted scripts

Parsing has independent resource limits: 1 MiB of source, 100,000 tokens, 128 active parser nesting levels, and a syntax tree depth of 256. Exceeding a limit raises `ParserException`; these limits also apply to `runExpression()`. Procedure execution allows at most 256 scopes, including the global scope, before raising `RunnerException`. Keep a cycle budget as well: a loop can run indefinitely without growing the call stack.

If you are running user submitted scripts, be careful about the security implications. 
- Always allow only trusted function providers.
- Set `setMaxCycles()` to a reasonable value to prevent infinite loops.
- Procer cannot access PHP globals, files, or `eval` — but the functions you expose can. Keep custom function providers side-effect-free where possible.

## Serialized process snapshots

Serialized `Process` data is executable VM state. Treat it like a session token:

- Store it server-side when possible, or sign/encrypt it before returning it to a client.
- Do not resume snapshots that a user can tamper with directly. A modified snapshot may skip waits, change variables, or point execution at different bytecode.
- Deserialization validates the snapshot shape and rejects unknown opcodes, but it is not an authorization boundary.

Snapshots are limited to 4 MiB, with a maximum serialized value depth of 128. Cyclic arrays/objects, non-finite floats, corrupt type prefixes, invalid instruction arguments, and out-of-range pointers are rejected with serialization/deserialization exceptions. This validates structure, not the provenance or intent of bytecode. Application-defined serialization callbacks and function providers remain responsible for their own behavior.

## Objects exposed to scripts

Only pass objects that are safe for scripts to inspect. The `of` accessor can read public properties and call public zero-required-argument methods/getters. Do not expose objects with side-effecting public methods unless that behavior is intentional.
