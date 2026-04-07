# In-Depth / Advanced Topics

This document covers internal tools, advanced patterns, and less common features that most users won't need day to day.

---

## Table of contents
- [InterruptReason — why execution paused](#interruptreason)
- [Inspecting why a process stopped](#inspecting-why-a-process-stopped)
- [Debugging compiled bytecode](#debugging-compiled-bytecode)
- [MathExpressionReflection — static analysis of expressions](#mathexpressionreflection)
- [The `stopping` keyword](#the-stopping-keyword)
- [How the VM cycle counter works](#how-the-vm-cycle-counter-works)

---

## InterruptReason

`Context::getInterruptReason()` returns a `\Karboosx\Procer\Interrupt\InterruptReason` enum case (or `null` if execution is still ongoing). Use it to understand precisely *why* execution paused instead of branching on `isFinished()` alone.

| Case | Meaning |
|---|---|
| `STOP` | A `stop.` statement was reached. Resume with `$procer->resume()`. |
| `WAIT_FOR_SIGNAL` | A `wait for signal` or `wait for all signals` statement was reached and the required signals were not present. Resume with the right signals. |
| `FUNCTION_REQUEST` | A function provider returned an `Interrupt` object. The function wants to be paused and retried. |
| `RETURN` | A `return` statement at the top level of the script was executed. Execution is complete. |
| `WHILE_STOPPING` | A `while stopping` loop finished one iteration and auto-paused (see [The `stopping` keyword](#the-stopping-keyword)). |

```php
use Karboosx\Procer\Interrupt\InterruptReason;

$context = $procer->run($code);

match ($context->getInterruptReason()) {
    InterruptReason::WAIT_FOR_SIGNAL => handleWait($context),
    InterruptReason::STOP            => handleStop($context),
    InterruptReason::FUNCTION_REQUEST => handleInterrupt($context),
    InterruptReason::RETURN          => handleReturn($context),
    null                             => handleFinished($context),
    default                          => throw new \UnexpectedValueException(),
};
```

### Checking what signals are being waited for

When `getInterruptReason()` returns `WAIT_FOR_SIGNAL`, you can read exactly which signals the script is waiting for:

```php
$waiting = $context->getWaitForSignalValue(); // e.g. ['payment_received', 'order_confirmed']
```

---

## Inspecting why a process stopped

Combining `InterruptReason` with serialization lets you build reliable pause/resume workflows:

```php
$context = $procer->run($code);

if (!$context->isFinished()) {
    $reason  = $context->getInterruptReason();
    $waiting = $context->getWaitForSignalValue();
    $data    = $context->getInterruptData();   // extra data from Interrupt, if any
    $dump    = $context->serialize();

    // Persist $dump, $reason, $waiting to your storage layer.
    // On the next request, deserialize and resume with the right signals.
}
```

---

## Debugging compiled bytecode

`Procer::printIcCode()` and `Procer::printIcExpression()` compile code and return the human-readable bytecode without executing it. Useful for understanding what instructions a script produces and diagnosing unexpected behaviour.

```php
$procer = new \Karboosx\Procer\Procer();

echo $procer->printIcCode('let a be 1 + 2 * 3.');
```

Example output:
```
   0 PUSH_VALUE i:3
   1 PUSH_VALUE i:2
   2 MATH_OPERATOR *
   3 PUSH_VALUE i:1
   4 MATH_OPERATOR +
   5 SET_VARIABLE s:a
```

For a single expression:

```php
echo $procer->printIcExpression('a + b * c');
```

The instruction set uses these operations (among others):

| Instruction | Meaning |
|---|---|
| `PUSH_VALUE` | Push a literal onto the stack |
| `PUSH_VARIABLE` | Push a variable's value onto the stack |
| `PUSH_BUILD_IN` | Push a built-in constant (`true`, `false`, `null`) |
| `SET_VARIABLE` | Pop the stack and assign to a variable |
| `MATH_OPERATOR` | Pop two values, apply operator, push result |
| `FUNCTION_CALL` | Call a function provider |
| `OBJECT_FUNCTION_CALL` | Call an object function provider |
| `IF_NOT_JMP` | Conditional jump (used by `if`, `while`, `until`) |
| `JMP` | Unconditional jump |
| `WAIT_FOR_SIGNAL` | Pause until a signal is present |
| `STOP` | Pause execution (resumable) |
| `RET` | Return from a procedure |
| `INVERT_VALUE` | Boolean invert (used by `not` and `until`) |

---

## MathExpressionReflection

`\Karboosx\Procer\Debug\MathExpressionReflection` lets you statically inspect an expression — extracting the variable names and function names it references — without executing it. Useful for permission checks, UI hints, or form validation.

```php
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Debug\MathExpressionReflection;

$parser     = Parser::default();
$expression = $parser->parseExpression('price * quantity + discount(user)');
$ref        = new MathExpressionReflection($expression);

$ref->getVariables();  // ['price', 'quantity', 'user']
$ref->getFunctions();  // ['discount']
```

`getVariables()` returns every identifier that is used as a variable reference (not a function call). `getFunctions()` returns every function name that appears in a call position. Both return arrays of unique strings.

> **Note:** `Parser::default()` creates a Parser in indentation mode (no `done` keyword). If your expressions come from `done`-keyword mode scripts, use `new Parser(new Tokenizer(), true)`.

---

## The `stopping` keyword

`while stopping <condition> do` is a special variant of the `while` loop that automatically pauses execution at the end of each iteration (as if `stop.` were appended). On the next `resume()` call, the condition is re-evaluated and the loop either continues or exits.

```procer
while stopping work_left > 0 do
    on queue process_one.
```

This produces the same result as:

```procer
while work_left > 0 do
    on queue process_one.
    stop.
```

It is useful when you want to process one item per resume cycle — for example, draining a queue one step at a time from an HTTP endpoint.

When paused by `stopping`, `getInterruptReason()` returns `InterruptReason::WHILE_STOPPING`.

---

## How the VM cycle counter works

Every bytecode instruction that executes increments an internal counter (`Process::$cycles`). The counter is **cumulative across `resume()` calls** — it reflects the total work done by the process from first `run()` to termination.

When `setMaxCycles($n)` is set, a `MaxCyclesException` is thrown as soon as `cycles >= n`. Because the check happens *after* each instruction, the actual number of instructions executed when the exception fires may be slightly higher than `n` if the limit is hit mid-expression.

To read how many cycles a finished process consumed:

```php
$context = $procer->run($code);
$cycles  = $context->getProcess()->cycles;
```

This can inform dynamic limit tuning — for example, tracking average cycles per script type and adjusting limits accordingly.
