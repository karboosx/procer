# Security

## Limiting the number of cycles

The number of cycles can be limited using `Karboosx\Procer::setMaxCycles($maxCycles)`:

```php
$procer = new Karboosx\Procer();
$procer->setMaxCycles(10000);
```

When the limit is reached a `\Karboosx\Procer\Exception\MaxCyclesException` is thrown.

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

If you are running user submitted scripts, be careful about the security implications. 
- Always allow only trusted function providers.
- Set `setMaxCycles()` to a reasonable value to prevent infinite loops.
- Procer cannot access PHP globals, files, or `eval` — but the functions you expose can. Keep custom function providers side-effect-free where possible.