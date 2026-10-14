# Testing and parser reliability

[Documentation index](README.md)

Install the locked development dependencies with `composer install`, then run:

```sh
composer test
composer test:stress
composer test:mutations
composer test:docs
```

The ordinary suite includes the stress group at 1,000 iterations per randomized campaign. Tests are deterministic and report their seed, case index, and source on failure. No PHP `eval` is used to calculate expected expression results.

## Documentation checks

`composer test:docs` checks the README, every guide in `docs/`, and the editor installation guide. It compiles all `procer` fenced snippets, runs complete PHP examples in separate processes, compares their exact output, and verifies repository-local file and heading links. PHP warnings fail examples. Children use the current PHP binary, a 128 MiB memory limit, and a 15-second wall-clock timeout; no external services are needed. Temporary files are removed from `.phpunit.cache` after the check. Remote URLs and illustrative PHP fragments are not executed or link-checked.

When adding documentation:

- Put language snippets in `procer` fences. They are compiled in indentation mode by default; place `<!-- verify:procer done -->` immediately before a fence demonstrating explicit `done` mode.
- Make complete PHP examples start with `<?php` and load `vendor/autoload.php`. Place `<!-- verify:php {"output":"42"} -->` immediately before the fence, using the exact expected stdout as a JSON string. Examples run from the repository root.
- Describe incomplete PHP snippets as fragments and state their dependencies. Do not mark them as standalone programs.
- Keep examples small and generic; the application's original process does not belong in documentation or fixtures.
- Run `composer test:docs` after changing headings, links, examples, or API behavior. CI also runs this check across its PHP/OS matrix.

The documentation audit covers 19 Markdown files, 65 compiled Procer snippets, 16 executed PHP examples, and 151 local links. It passes on Windows with PHP 8.2.8 and 8.4.26; the full 2,581-test suite was also rerun successfully on PHP 8.4.26. The local PHP 8.2 check used a temporary copy of its configuration with obsolete Xdebug settings removed, so debugger startup messages did not contaminate expected output. The installed configuration was unchanged. These checks verify examples and navigation; behavior claims also require review against source and the language tests below.

## Extended stress runs

For a larger campaign in PowerShell:

```powershell
$env:PROCER_FUZZ_CASES = '10000'
$env:PROCER_FUZZ_SEED = '20261014'
composer test:stress
```

On Linux/macOS:

```sh
PROCER_FUZZ_CASES=10000 PROCER_FUZZ_SEED=20261014 composer test:stress
```

Change the seed to explore different inputs; keep a failing seed to reproduce it. The iteration setting applies separately to arithmetic, precedence, control flow, source mutation, arbitrary-byte, and workflow campaigns. The stress command uses a 256 MiB memory limit and a 120-second execution limit. GitHub Actions runs the full suite and a larger campaign on PHP 8.2–8.5, on Linux and Windows; its workflow run number supplies an additional reproducible seed.

## What the tests check

- Malformed/truncated statements, mandatory commas, duplicate declarations, unknown bytes, quotes, parentheses, and both block modes.
- First-line indentation, nested branch ownership, inconsistent dedents, blank lines, comments, LF/CRLF/CR line endings, and diagnostic positions.
- Generated arithmetic evaluated independently, operator precedence and associativity, unary signs, short-circuit guards, and numeric boundaries.
- Generated procedures, loops, and branches with expected results in both block modes, including stop/serialize/deserialize/resume and stack cleanup.
- Every possible byte at lexical boundaries, every truncation of representative programs, seeded insertions/deletions/replacements, and arbitrary byte strings. Each must compile and execute within a cycle budget or raise a library exception; raw PHP errors and warnings fail the tests.
- Bounded rejection of excessive tokens, source size, nesting, syntax tree depth, recursion, cyclic values, and nested-object serialization expansion; a 5,000-statement valid program must still execute correctly.
- Snapshot structure, typed values, precision-preserving float round trips, return values, instruction arguments, and pointer ranges.
- An interruption matrix across both block syntaxes, both interrupt types, and in-memory versus fresh-interpreter restoration: nested arguments, object calls, recursive procedures, branches, loop bounds, and short-circuit expressions. Completed calls must not repeat after `AFTER_EXECUTION`.
- Serialization/restoration at every executed instruction boundary in a representative corpus, including mutual recursion, parameter shadowing, sparse arrays, nested loops, properties, and implicit returns. Final values, scopes, callback traces, and cycle counts must match uninterrupted execution.
- Repeated signal waits, stopping loops, expression interruptions, callback/getter failures followed by retries, failed lazy object loading, completed lazy return values, and rejection of same-interpreter reentrancy.
- All nested branch truth-table combinations under LF/CRLF/CR, several indentation styles, comments, and root indentation; every byte inside quoted strings; Unicode and long runs of escaped quotes/backslashes through snapshots.

## Language combinations

The dedicated language matrices are in `LanguageCompositionTest` and `LanguageGrammarBoundaryTest`. Run them with `php vendor/bin/phpunit --filter Language`.

| Language behavior | Cases exercised |
| --- | --- |
| Two nested block forms | All 81 ordered pairs of `if`, skipped `if`, `or`, `else`, ascending range, descending range, `for each`, `while`, and `until`; both block syntaxes |
| Early procedure returns | All those pairs, with a return inside the inner block; checks hoisting, result, and scope cleanup |
| Three nested block forms | All 729 ordered triples in both syntaxes; exact iteration traces checked against an independent Cartesian-product oracle |
| Object call spellings | Eight forms across 13 expression/statement contexts and both syntaxes, including arguments, unary operators, comparisons, conditions, and range endpoints |
| Binary operator combinations | All 225 ordered pairs of the 15 supported operator spellings, each with five operand triples; independent precedence/associativity evaluation, including short-circuited division by zero |
| Grammar boundaries | Wrapped headers, malformed argument lists across six call forms, keyword prefixes, identifier case, parameter/global/caller scope, and non-tail recursion |

`composer test:mutations` verifies that the suite catches 23 deliberate language faults: wrong precedence, duplicate parameters, misplaced block bodies, object-call delimiters, branch ownership, argument order, short-circuiting, existence negation, arity, return values, range endpoints, iteration order, duplicate record fields, record evaluation/binding order, failure routing/propagation, step unwinding, temporary binding restoration, snapshot frame completeness, and contextual loop names. It first requires a passing unmodified baseline, syntax-checks each altered class, and runs it in a separate PHP process with a wall-clock limit. Only PHPUnit failures/errors count as detection; syntax errors and infrastructure failures do not. Altered classes are loaded from temporary files under `.phpunit.cache`; repository source files are never rewritten. The command fails if a fault survives and saves its summary to `.phpunit.cache/language-mutation-results.json`. These are targeted checks, not an exhaustive mutation score.

For coverage, enable Xdebug or PCOV and run `php vendor/bin/phpunit --coverage-text`. With Xdebug, set `xdebug.max_nesting_level=2048` for the deliberate nesting tests so Xdebug's own debugger limit does not preempt the parser's limit. The application does not require a coverage extension.

## Record and workflow coverage

`RecordExpressionTest`, `WorkflowLanguageTest`, `WorkflowRecoveryTest`, `WorkflowCompositionTest`, `WorkflowEdgeCaseTest`, `WorkflowReviewTest`, `WorkflowSnapshotReviewTest`, and `WorkflowGrammarReviewTest` add 1,312 test cases over the previous hardening suite. Run them with `php vendor/bin/phpunit --filter 'RecordExpression|Workflow'`. Fixtures are small, independent language programs and deterministic providers; the user's application script is not stored or loaded by the repository.

- Records: single/multiple/nested fields, source-object properties, all expression contexts, function-argument comma boundaries, left-to-right evaluation, duplicate/invalid labels, quoted bytes and Unicode, reflection, snapshots, and a 5,000-field record checked field by field. The rejected `with` call syntax remains rejected.
- Recovery: success, nearest handler, failures inside handlers, recursive procedure unwinding, temporary binding restoration/shadowing, explicit cleanup, returns, named loop advancement, and fatal errors bypassing handlers. Both block modes exercise all 81 ordered pairs of existing block forms with successful and failing steps, plus all eight three-level handler arrangements.
- Resumption: 12 workflow programs across both block modes, both interrupt types, three line endings, and in-memory versus fresh-interpreter restoration (288 combinations), plus restoration at every executed instruction boundary. Stop/signal waits, a handler sharing its loop target's name, and an unhandled cleanup failure followed by restoration and host retry are included.
- Saved state: version 1 compatibility, version 2 frames, missing/corrupt frame rejection, new instruction schemas, and preserving failure bindings and pending record values. Completed calls are not repeated after `AFTER_EXECUTION`; the existing argument reevaluation contract for `BEFORE_EXECUTION` is tested explicitly.
- Review matrix: failures in 16 evaluation positions (including conditionals, loop setup, records, function/object arguments, and returns), each directly, inside a nested step, and across a procedure call, in both block modes. Twenty loop names are tested with both loop forms, both block modes, and `next` in bodies and handlers; sparse and empty inputs are included.
- Improper use: invalid targets and headers, malformed records, keyword names, scope boundaries, returned error-shaped values, and fatal runtime errors. Invalid compilation must have no provider side effects and must leave the interpreter/compiler reusable. The ambiguous-looking `on failure do cleanup.` is explicitly tested as an existing object call, not a handler.
- Restoration review: lazy loading fails during record assembly, with and without a handler, then retries in memory or through another snapshot. Tests also cover saved lazy bindings, failures from procedure handlers, null source locations, invalid active depths, version downgrades, every frame field's invalid types, unsupported saved values, and invalid UTF-8 labels.
- Seeded workflow campaigns compare generated records and failure schedules with independent expected results. Every byte and truncation at new grammar boundaries must execute within a budget or raise a library exception, without PHP warnings or raw errors.

## Compatibility changes

Malformed input that was previously accepted can now fail: missing/trailing argument commas, duplicate procedure/parameter names, orphan block delimiters, and missing or inconsistent indentation. Use `useDoneKeyword()` for inline blocks. `nothing.` is an explicit empty body in indentation mode.

Logical `and`/`or` now skip unnecessary operands. Numeric literals no longer silently saturate outside their representable range. Quoted strings support escaped quotes and backslashes. Range loops support descending steps and reject zero/non-progressing steps. Procedure argument counts are enforced. Debug printing no longer changes the active program. The cycle budget is checked before execution, and completed returns remain completed after resume.

Snapshot format version 1 remains readable. An optional `e` scope field preserves the main script's external return value; older snapshots without it still deserialize. Structurally corrupt snapshots that previously happened to load now fail. Newly compiled bytecode should be resumed with this version of the library, since range and array iteration use additional internal functions.

Programs using records, named steps, or `next` serialize as version 2 to preserve the new instructions and active handlers. Programs using only existing instructions still emit version 1. Providers opt into step recovery by throwing `OperationFailure`; other exceptions keep their existing behavior. See [Error handling](error_handling.md#recoverable-operation-failures-in-procer) for the provider migration contract.

Interrupted `runExpression()` calls now return `null` instead of consuming partial operands. Failed runtime operations preserve their operands for retries. Lazy objects on the evaluation stack and completed return values resolve on use. Reentrant execution on the same interpreter raises `RunnerException`; nested execution on a separate interpreter is supported. Public static properties can be read without PHP warnings.

Bare object calls in range headers no longer consume `to (...)` or `by (...)` as method names. Wrapped headers in indentation mode require the body to begin after the `do` line. Float snapshots use a locale-independent 17-digit representation, preserving their bits even when PHP's global precision settings differ.

## Verified workflow results (2026-10-14)

The complete suite passes **2,581 tests and 79,003 assertions** on Windows with PHP 8.2.8 and PHP 8.4.26. The review added 420 independent regression cases and removed 31 application-specific cases. It found and fixed rejection of `next on.` for a legal loop named `on`, while preserving `next on object.` method calls. All 23 targeted mutation faults are detected on both PHP versions. Syntax checks pass for all 108 PHP source/test/tool files. The two highlighting JSON files parse and match, `git diff --check` passes, and a repository scan finds none of the application's URL, provider names, or removed fixture references.

Expanded workflow stress runs used 10,000 iterations per randomized campaign, including the existing parser campaigns:

| PHP | Seed | Tests | Assertions | Result |
| --- | --- | ---: | ---: | --- |
| 8.4.26 | 20261017 | 24 | 324,960 | Passed |
| 8.4.26 | 17 | 24 | 324,894 | Passed |
| 8.2.8 | 20261018 | 24 | 324,979 | Passed |
| 8.4.26 | 20261019 | 24 | 324,449 | Passed after review |

The reviewed suite was also run with Xdebug 3.2.2 on PHP 8.2.8. Its raw coverage report gives:

| Component | Executed / executable lines | Line coverage |
| --- | ---: | ---: |
| Tokenizer | 82 / 82 | 100% |
| Parser | 611 / 614 | 99.51% |
| Bytecode compiler | 291 / 294 | 98.98% |
| Runner | 463 / 486 | 95.27% |
| Serializer | 95 / 98 | 96.94% |
| Deserializer | 266 / 287 | 92.68% |
| All source files | 2,064 / 2,136 | 96.63% |

Every added executable source line was exercised except the defensive check that an active frame references `ENTER_STEP`: the preceding completeness check already rejects that invalid reference. Corrupt references are tested through that earlier rejection. These are line-coverage measurements, not exhaustive branch coverage or proof that every possible input is correct. PHP 8.3/8.5 and Linux remain for CI to verify.

## Previous hardening results (2026-10-14)

The baseline had 415 tests and 851 assertions. The initial hardening pass reached 564 tests; the interaction and recovery pass reached 676. The language pass added 585 language test cases, plus eight numeric configuration cases completed before that focus was narrowed. That suite passed 1,269 tests and 55,599 assertions on Windows with both PHP 8.2.8 and PHP 8.4.26. All 14 targeted language faults were detected on both PHP versions; the Composer mutation command was also exercised. Syntax checks passed for all 95 PHP source/test/tool files, and `composer validate --strict` passed.

Expanded stress runs used 10,000 iterations per randomized campaign. The first three seeds were run during the initial hardening pass; seed 20261015 was run after the interaction and recovery fixes, and seed 20261016 after the language grammar fixes:

| Seed | Assertions | Result |
| --- | ---: | --- |
| 20261014 | 259,732 | Passed |
| 17 | 259,427 | Passed |
| 8675309 | 258,759 | Passed |
| 20261015 | 259,056 | Passed |
| 20261016 | 260,030 | Passed |

The 676-test suite, before the additional language matrices, was measured using Xdebug 3.2.2 on PHP 8.2.8. These figures have not been remeasured after the latest grammar changes:

| Component | Executed / executable lines | Line coverage |
| --- | ---: | ---: |
| Tokenizer | 82 / 82 | 100% |
| Parser | 554 / 557 | 99.46% |
| Bytecode compiler | 247 / 250 | 98.80% |
| All source files | 1,820 / 1,900 | 95.79% |

These are line-coverage figures, not branch-coverage or correctness proofs. The installed coverage text formatter failed on an uninitialized property inside `php-code-coverage`; the raw report generated by `--coverage-php` succeeded, and the table was calculated from its line data, including uncovered source files. No vendor files were modified. The GitHub Actions matrix is configured but has not been executed locally; PHP 8.3/8.5 and Linux results remain for CI to verify.

## Limits of the evidence

These tests substantially increase confidence; they do not prove that every possible program is correct. The input space is unbounded. Exposed PHP functions/getters and serialization callbacks can still fail or have side effects. Runtime loops require an application-selected cycle limit. Serialized bytecode must still come from a trusted or authenticated source. See [Security](security.md) for the limits and integration requirements.
