# Signals
Signals are a way to allow to control the flow of the business logic inside Procer code.
The idea is to allow the developer to define a set of signals that can be emitted at any point in the php code and send it to a Procer code during the execution of the business logic.

## Emitting signals
To emit a signal, you can pass an array of signals to the `run` method. Here is an example:
```php
$procer->run('wait for signal test.', [], ['test']);
```

You can pass multiple signals.

The `resume` method can be used to emit a signal during the execution of the business logic. Here is an example:
```php
$procer->resume(null, [], ['test2']);
```

You can pass different signals to the `resume` method when resuming the execution of the business logic.

> **Note**: The first empty array is for the variables that you want to pass to the Procer code.

## Receiving signals
To receive a signal, you can use the several ways to control the flow of the business logic. 

### Using the `wait for signal` statement
To wait for a signal, you can use the `wait for signal` statement followed by the signal name. Here is an example:
```procer
wait for signal test.
```

Alternatively, you can skip the `signal` word:
```procer
wait for test.
```

### Accessing the signal wait value

If you want to access the value of the signal from php code, you can use the `getSignalWaitValue()` method on the result of the `Karboosx\Procer::run($script)` method. Here is an example:
```php
$procer = new Karboosx\Procer();

$result = $procer->run('wait for signal test.');

echo $result->getSignalWaitValue(); // test
```

This statement will pause the execution of the business logic until the signal is emitted.

### Using the `signal is` conditional statement
To execute code when a signal is emitted, you can use the `if signal is X` statement where `X` is the signal name. Here is an example:
```procer
if signal is test do
    on console print("signal test received").
```

This statement will execute the code inside the block when the signal is emitted.

## Examples

### Processing some data while waiting for a signal
```procer
while signal is not test do
    on console print("waiting for signal test").
    stop.
```

> **Important**: The `stop` statement is used to pause the execution of the business logic until the signal is emitted.
> Otherwise, the code will continue to execute without waiting for the signal.

### Emitting multiple signals
```php
$procer->run('wait for signal test.', [], ['test', 'test2']);
```

```procer
if signal is test do
    on console print("signal test received").
or signal is test2 do
    on console print("signal test2 received").
```