# Getting started

[Documentation index](README.md)

This guide runs entirely in PHP with no external services. It introduces the language, then the integration needed for recoverable failures and persistence.

## Install

Requires PHP 8.2 or later, `ext-ctype`, and a Composer project:

```sh
composer require karboosx/procer
```

The main class is **`Karboosx\Procer\Procer`**. Run the complete PHP examples below from the directory containing `vendor/autoload.php`.

This guide describes the code in this checkout. If you are evaluating changes before they are released as a package, run `composer install` in the repository and run the examples there.

## Calculate a result

<!-- verify:php {"output":"40EUR"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();
$procer->setMaxCycles(1000);
$context = $procer->run(<<<'PROCER'
let total be unit_price * quantity.
return total as "amount", "EUR" as "currency".
PROCER, ['unit_price' => 8, 'quantity' => 5]);

$result = $context->getReturnValue();
echo $result->amount;   // 40
echo $result->currency; // EUR
```

`run()` starts a new process and returns a `Context`. Its second argument supplies global variables. `return` completes the main script; `getReturnValue()` exposes its result to PHP.

The expression `total as "amount", "EUR" as "currency"` constructs a record. `total` is a variable; `"amount"` and `"currency"` are field names; `"EUR"` is a string value. See [Records](records.md) for copying fields from existing objects and passing records to functions.

## Add a PHP operation and handle failure

This complete example registers a function that doubles an integer. Negative inputs are an expected operational failure in this demonstration.

<!-- verify:php {"output":"2112"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\OperationFailure;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Procer;

final class CalculationFunctions implements FunctionProviderInterface
{
    public function supports(string $functionName): bool
    {
        return $functionName === 'calculate';
    }

    public function calculate(Context $context, int $input): int
    {
        if ($input < 0) {
            throw new OperationFailure('The input must be non-negative', 1001);
        }
        return $input * 2;
    }
}

$procer = new Procer([new CalculationFunctions()]);
$procer->setMaxCycles(1000);
$context = $procer->run(<<<'PROCER'
let completed be 0.
let rejected be 0.
let total be 0.

for each number in numbers do
    step "Calculate one value" do
        let value be calculate(number).
    on failure as problem do
        let rejected be rejected + 1.
        next number.

    let total be total + value.
    let completed be completed + 1.

return completed as "completed", rejected as "rejected", total as "total".
PROCER, ['numbers' => [2, -1, 4]]);

$result = $context->getReturnValue();
echo $result->completed; // 2
echo $result->rejected;  // 1
echo $result->total;     // 12
```

On success, the handler is skipped. On `OperationFailure`, the remaining step body is skipped and the handler receives a diagnostic record. `next number.` advances the loop, so the failed iteration never uses a stale `value` from an earlier iteration.

A `TypeError`, missing function, or ordinary application exception returns to PHP. There is no automatic retry or rollback. See [Steps and recovery](workflows.md).

## Choose a block style

Indentation is the default. A block body must start on a later line and be indented more than its header. Use spaces consistently.

Call `$procer->useDoneKeyword();` before compiling if you prefer explicit closers. The equivalent step fragment is:

<!-- verify:procer done -->
```procer
step "Calculate one value" do
    let value be calculate(number).
on failure as problem do
    let rejected be rejected + 1.
done
```

One `done` closes the step, including its handler. Each other enclosing block needs its own closer in this mode. `useDoneKeyword(false)` restores indentation mode for future compilations.

## Pause for a decision

<!-- verify:php {"output":"falseapproved15"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();
$procer->setMaxCycles(1000);
$context = $procer->run('wait for signal approved. return amount.', ['amount' => 12]);

var_export($context->isFinished());          // false
echo implode(',', $context->getWaitForSignalValue()); // approved

$context = $procer->resume(null, ['amount' => 15], ['approved']);
echo $context->getReturnValue();             // 15
```

The first `resume()` argument is an optional saved `Process`; `null` uses the current process. The second merges global variable updates. The third replaces the signals for that call. Signals do not accumulate across resumes.

`stop.` resumes immediately after the statement without requiring a signal. Providers can return an [Interrupt](interrupts.md) when PHP needs to decide when to continue.

## Save and resume in another interpreter

<!-- verify:php {"output":"42"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;

$first = new Procer();
$context = $first->run('let answer be 42 as "value". stop. return answer.');
$snapshot = $context->serialize(); // Persist in trusted application storage.

$process = (new Deserializer())->deserialize($snapshot);
$second = new Procer();
$second->setMaxCycles(1000);
$finished = $second->resume($process);
echo $finished->getReturnValue()->value; // 42
```

A snapshot contains execution state, not provider instances or interpreter configuration. Register any providers the remaining script needs in the new interpreter. Plain records need no custom deserializer; domain objects may need one. See [Serialization](serialization.md).

## Common first mistakes

| Symptom | Check |
| --- | --- |
| PHP cannot find the interpreter | Import `Karboosx\Procer\Procer` and load Composer's autoloader |
| A function is not found | Register a provider accepting that exact name in `supports()` |
| A handler never runs | Throw `OperationFailure`; returning an error-shaped record does not raise a failure |
| Later code uses an old value after failure | Put dependent operations in the step or use `next`/`return` in the handler |
| A record argument does not parse | Assign it first, or wrap the entire record in parentheses inside the argument list |
| `wait for all signals a, b.` keeps waiting | Supply both signals in the same execution call |
| Resume immediately hits the cycle limit | The counter is cumulative; configure an appropriate total budget |

Continue with [Syntax](syntax.md), the [PHP API](php_api.md), or [Migration](migration.md).
