# Procer documentation

Procer has two parts: a language for describing a process, and PHP providers that implement its operations. A function in a script exists only if the application registers it or the script defines a procedure with that name.

These pages describe the implementation in this repository. Check that your installed package version contains the features you need; a repository change does not imply a published release.

## Start here

1. Follow [Getting started](getting_started.md) to run a script, register an operation, handle failure, and save a paused process.
2. Read [Syntax](syntax.md) for language basics and the choice between indentation and `done` blocks.
3. Read [Records](records.md) and [Steps and recovery](workflows.md) for the new workflow features.
4. Use [Migration](migration.md) when adapting existing providers or saved processes.

## For script authors

| Guide | What it explains |
| --- | --- |
| [Syntax](syntax.md) | Statements, naming, blocks, variables, calls, conditions, loops, and procedures |
| [Expressions](expressions.md) | Values, precedence, comparison, short-circuiting, and evaluation order |
| [Records](records.md) | `value as "field"`, reading fields, nesting, grouping, and validation rules |
| [Steps and recovery](workflows.md) | Success/failure paths, diagnostics, nested handlers, cleanup, and `next` |
| [Signals](signals.md) | Waiting for events and checking signals supplied by the host |

## For PHP application developers

| Guide | What it explains |
| --- | --- |
| [PHP API](php_api.md) | `Procer`, `Context`, lifetime, variables, signals, and configuration |
| [Custom functions](custom_functions.md) | Registration, argument types, records, failures, and permissions |
| [Object functions](object_functions.md) | Operations on application objects and the separate `of` accessor |
| [Interrupts](interrupts.md) | Provider pauses and what each interrupt repeats on resume |
| [Serialization](serialization.md) | Snapshot versions, supported values, object restoration, and lazy loading |
| [Error handling](error_handling.md) | Exceptions, diagnostics, propagation, and host-driven retries |
| [Migration](migration.md) | Adopting records and recovery without replacing existing providers |
| [Security](security.md) | Capability exposure, cycle/resource limits, and trusted snapshots |

## For maintainers and advanced users

- [Advanced topics](in_depth.md): runtime states, instruction inspection, expression reflection, and cycle accounting.
- [Testing and reliability](testing.md): commands, coverage matrices, seeded campaigns, and verified results.
- [Editor setup](../syntaxes/INSTALL.md): TextMate grammar installation and supported highlighting.

## Reading the examples

Complete PHP examples start with `<?php` and load `vendor/autoload.php`; run them from the Composer project directory. A snippet described as a *fragment* depends on the variables, providers, or enclosing class stated above it. Names such as `calculate`, `log`, and `save` are illustrative application functions, not built-ins.

Ordinary statements end with `.`. Block headers end with `do`; a `done` closer has no period. A comma continues an argument list or record. Line breaks inside an expression are formatting; indentation between statements determines block ownership in the default mode.

The guides use small, independent examples. They do not include a particular application's import process or require external HTTP or AI services.
