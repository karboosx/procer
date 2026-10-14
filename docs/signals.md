# Signals

[Documentation index](README.md) · [PHP API](php_api.md)

A signal is a name supplied by PHP to one `run()` or `resume()` call. Scripts can wait for these names or test their presence. Signals are not asynchronous callbacks or message payloads; the host decides when to invoke the interpreter.

## Supply signals

Both APIs use the third argument for signals:

```php
$context = $procer->run($code, ['amount' => 12], ['approved']);
$context = $procer->resume(null, ['amount' => 15], ['approved']);
```

The second argument supplies global variables. Use those variables for event data.

Each call **replaces** the available signal set. Signals are not accumulated or consumed by waits. A name can satisfy multiple waits in the same call, but must be supplied again on a later resume if it is still needed. Signals are not persisted in snapshots.

## Wait for one or any signal

These forms wait for a single signal:

```procer
wait for signal approved.
```

```procer
wait for approved.
```

A comma-separated list waits for any one of the names:

```procer
wait for approved, cancelled.
```

If the condition is already satisfied, execution continues immediately. Otherwise the wait remains pending, `isFinished()` is false, and the reason is `WAIT_FOR_SIGNAL`. On resume, the same wait checks the new signal set.

## Wait for all signals

```procer
wait for all signals approved, verified.
```

The word `signals` is required after `all`. Both names must be present in the **same** execution call. Supplying `approved` on one resume and only `verified` on the next does not satisfy this wait.

<!-- verify:php {"output":"falsefalse7"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();
$context = $procer->run('wait for all signals approved, verified. return 7.');
$context = $procer->resume(null, [], ['approved']);
var_export($context->isFinished()); // false
$context = $procer->resume(null, [], ['verified']);
var_export($context->isFinished()); // false
$context = $procer->resume(null, [], ['approved', 'verified']);
echo $context->getReturnValue();    // 7
```

If events arrive separately, accumulate their names in application storage or record explicit state variables and use a condition expressing that state.

## Test signal presence

This fragment requires a registered `log` function:

```procer
if signal is approved do
    log("approved").
or signal is cancelled do
    log("cancelled").
```

`signal is not approved` tests absence. Signal names are identifiers, not quoted strings. The host should pass matching string names with the same case.

## Inspect a pending wait

```php
$names = $context->getWaitForSignalValue(); // e.g. ['approved', 'verified']
$reason = $context->getInterruptReason();
```

`getWaitForSignalValue()` returns an array of requested names or null. It is not the signal's data and does not say whether the wait requires any or all names. Keep application routing information alongside a saved process when the host needs it.

## Signals inside steps

Waiting is a pause, not a failure. It does not enter `on failure`. Active steps and temporary handler bindings survive the wait and snapshot restoration. A handler can itself wait for a signal.

`stop.` pauses unconditionally; it does not subscribe to any event. A `while` loop that polls a signal without a pause can exhaust the cycle budget. Use an explicit wait, `stop`, or a `stopping` loop according to the intended process. See [Advanced topics](in_depth.md#the-stopping-keyword).
