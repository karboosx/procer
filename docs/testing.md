# Testing and parser reliability

Install the locked development dependencies with `composer install`, then run:

```sh
composer test
composer test:stress
composer test:mutations
```

The ordinary suite includes the stress group at 1,000 iterations per randomized campaign. Tests are deterministic and report their seed, case index, and source on failure. No PHP `eval` is used to calculate expected expression results.

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

Change the seed to explore different inputs; keep a failing seed to reproduce it. The iteration setting applies separately to arithmetic, precedence, control flow, source mutation, and arbitrary-byte campaigns. The stress command uses a 256 MiB memory limit and a 120-second execution limit. GitHub Actions runs the full suite and a larger campaign on PHP 8.2–8.5, on Linux and Windows; its workflow run number supplies an additional reproducible seed.

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

`composer test:mutations` verifies that the suite catches 14 deliberate language faults: wrong precedence, duplicate parameters, misplaced block bodies, object-call delimiters, branch ownership, argument order, short-circuiting, existence negation, arity, return values, range endpoints, and iteration order. It first requires a passing unmodified baseline, syntax-checks each altered class, and runs it in a separate PHP process with a wall-clock limit. Only PHPUnit failures/errors count as detection; syntax errors and infrastructure failures do not. Altered classes are loaded from temporary files under `.phpunit.cache`; repository source files are never rewritten. The command fails if a fault survives and saves its summary to `.phpunit.cache/language-mutation-results.json`. These are targeted checks, not an exhaustive mutation score.

For coverage, enable Xdebug or PCOV and run `php vendor/bin/phpunit --coverage-text`. With Xdebug, set `xdebug.max_nesting_level=2048` for the deliberate nesting tests so Xdebug's own debugger limit does not preempt the parser's limit. The application does not require a coverage extension.

## Compatibility changes

Malformed input that was previously accepted can now fail: missing/trailing argument commas, duplicate procedure/parameter names, orphan block delimiters, and missing or inconsistent indentation. Use `useDoneKeyword()` for inline blocks. `nothing.` is an explicit empty body in indentation mode.

Logical `and`/`or` now skip unnecessary operands. Numeric literals no longer silently saturate outside their representable range. Quoted strings support escaped quotes and backslashes. Range loops support descending steps and reject zero/non-progressing steps. Procedure argument counts are enforced. Debug printing no longer changes the active program. The cycle budget is checked before execution, and completed returns remain completed after resume.

Snapshot format version 1 remains readable. An optional `e` scope field preserves the main script's external return value; older snapshots without it still deserialize. Structurally corrupt snapshots that previously happened to load now fail. Newly compiled bytecode should be resumed with this version of the library, since range and array iteration use additional internal functions.

Interrupted `runExpression()` calls now return `null` instead of consuming partial operands. Failed runtime operations preserve their operands for retries. Lazy objects on the evaluation stack and completed return values resolve on use. Reentrant execution on the same interpreter raises `RunnerException`; nested execution on a separate interpreter is supported. Public static properties can be read without PHP warnings.

Bare object calls in range headers no longer consume `to (...)` or `by (...)` as method names. Wrapped headers in indentation mode require the body to begin after the `do` line. Float snapshots use a locale-independent 17-digit representation, preserving their bits even when PHP's global precision settings differ.

## Verified results (2026-10-14)

The baseline had 415 tests and 851 assertions. The initial hardening pass reached 564 tests; the interaction and recovery pass reached 676. The language pass added 585 language test cases, plus eight numeric configuration cases completed before that focus was narrowed. The completed suite passes 1,269 tests and 55,599 assertions on Windows with both PHP 8.2.8 and PHP 8.4.26. All 14 targeted language faults are detected on both PHP versions; the Composer mutation command was also exercised. Syntax checks pass for all 95 PHP source/test/tool files, and `composer validate --strict` passes.

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
