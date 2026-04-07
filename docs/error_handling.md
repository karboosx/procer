# Error Handling

All exceptions thrown by Procer extend `\Karboosx\Procer\Exception\ProcerException`, which itself extends `\Exception`. You can catch them individually or catch the base class to handle everything at once.

```php
use Karboosx\Procer\Exception\ProcerException;

try {
    $context = $procer->run($code);
} catch (ProcerException $e) {
    // any Procer error
}
```

---

## Exception hierarchy

```
ProcerException
├── ParserException              — syntax error while parsing
│   └── IcParserException        — internal compilation error (rare)
├── RunnerException              — runtime error during execution
│   ├── FunctionNotFoundException
│   ├── ObjectFunctionNotFoundException
│   ├── VariableNotFoundException
│   ├── PropertyNotFoundException
│   └── MaxCyclesException
├── SerializationException       — error while serializing a process
└── DeserializationException     — error while deserializing a process
```

---

## ParserException

Thrown when the tokenizer or parser encounters invalid syntax.

```php
use Karboosx\Procer\Exception\ParserException;

try {
    $procer->run('let a be .');   // syntax error
} catch (ParserException $e) {
    echo $e->getMessage();
    // e.g. "Expected a value, got '.' at line 1 position 9"
}
```

The message always includes the line number and position. If you want the surrounding source line included in the message, call `setCodeLine()` on the exception:

```php
} catch (ParserException $e) {
    $lines = explode("\n", $code);
    $token = $e->getToken();
    if ($token) {
        $e->setCodeLine($lines[$token->getLine() - 1] ?? '');
    }
    echo $e->getMessage();
    // let a be .
    //          ^
}
```

**IcParserException** is a subclass thrown during internal bytecode compilation. In practice you should never see it unless the AST is somehow corrupted — treat it as a bug report candidate.

---

## RunnerException

Thrown during execution for runtime problems. All `RunnerException` subclasses carry a `TokenInfo` with source location.

```php
use Karboosx\Procer\Exception\RunnerException;

try {
    $procer->run('let a be missing_var.');
} catch (RunnerException $e) {
    echo $e->getMessage();

    $info = $e->getTokenInfo(); // \Karboosx\Procer\IC\TokenInfo|null
    if ($info) {
        echo "line {$info->line}, col {$info->linePosition}";
    }
}
```

---

## VariableNotFoundException

Subclass of `RunnerException`. Thrown when a variable is referenced before being assigned.

```php
use Karboosx\Procer\Exception\VariableNotFoundException;

try {
    $procer->run('let a be missing.');
} catch (VariableNotFoundException $e) {
    echo $e->getVariableName();  // "missing"
    echo $e->getMessage();       // "Variable 'missing' is not defined at line 1 position 9"
}
```

---

## PropertyNotFoundException

Subclass of `RunnerException`. Thrown when a property or method does not exist on an object, or when accessing a property on a non-object value.

```php
use Karboosx\Procer\Exception\PropertyNotFoundException;

try {
    $procer->run('let a be missing of obj.', ['obj' => $myObj]);
} catch (PropertyNotFoundException $e) {
    echo $e->getPropertyName();  // "missing"
    echo $e->getObjectClass();   // e.g. "MyApp\Order"
    echo $e->getMessage();
    // "Property or method 'missing' not found on object of type 'MyApp\Order' at line 1 position 9"
}
```

When the variable is not an object at all, a plain `RunnerException` is thrown with a message like:
```
Cannot access property 'foo' on a non-object value (got integer) at line 1 position 9
```

---

## FunctionNotFoundException

Subclass of `RunnerException`. Thrown when a function is called but no provider supports it.

```php
use Karboosx\Procer\Exception\FunctionNotFoundException;

try {
    $procer->run('unknown_function().');
} catch (FunctionNotFoundException $e) {
    echo $e->getFunctionName();  // "unknown_function"
    echo $e->getMessage();
    // "Function 'unknown_function' is not defined. Make sure a FunctionProviderInterface that supports it is registered. at line 1 position 0"
}
```

---

## ObjectFunctionNotFoundException

Subclass of `RunnerException`. Thrown when an object method call has no matching provider, or when the variable being called on is not an object.

```php
use Karboosx\Procer\Exception\ObjectFunctionNotFoundException;

try {
    $procer->run('do_thing() on my_var.', ['my_var' => 42]);
} catch (ObjectFunctionNotFoundException $e) {
    echo $e->getFunctionName();  // "do_thing"
    echo $e->getObjectClass();   // "" (empty — my_var is not an object)
    echo $e->getMessage();
}
```

`getObjectClass()` returns the PHP class name if the variable is an object but no provider matched, or an empty string if the variable is not an object at all.

---

## MaxCyclesException

Subclass of `RunnerException`. Thrown when the execution exceeds the cycle limit set by `setMaxCycles()`.

```php
use Karboosx\Procer\Exception\MaxCyclesException;

$procer->setMaxCycles(10000);

try {
    $procer->run($possiblyInfiniteLoop);
} catch (MaxCyclesException $e) {
    echo $e->getMessage();
    // "Execution exceeded the maximum cycle limit of 10000. Increase setMaxCycles() or check for infinite loops."
}
```

See [Security](security.md) for guidance on choosing a cycle limit.

---

## SerializationException

Thrown when a value cannot be serialized. This happens when a variable holds a PHP object that does not implement `SerializableObjectInterface` or `JsonSerializableInterface`.

```php
use Karboosx\Procer\Exception\SerializationException;

try {
    $context->serialize();
} catch (SerializationException $e) {
    echo $e->getMessage();
    // "Cannot serialize object of type 'MyClass': it does not implement SerializableObjectInterface or JsonSerializableInterface."
}
```

Named constructors:
- `SerializationException::unsupportedObject(string $className)` — object with no serialization support
- `SerializationException::unsupportedType(string $type)` — unexpected PHP type (e.g. `resource`)
- `SerializationException::jsonEncodeFailed(string $context, string $reason)` — `json_encode` failure

---

## DeserializationException

Thrown when the serialized JSON is invalid, corrupt, or incompatible with the current Procer version.

```php
use Karboosx\Procer\Exception\DeserializationException;

try {
    $process = (new Deserializer())->deserialize($storedJson);
} catch (DeserializationException $e) {
    echo $e->getMessage();
}
```

Named constructors:
- `DeserializationException::invalidJson(string $reason)` — JSON cannot be parsed
- `DeserializationException::versionMismatch(int $expected, int $actual)` — serialized with a different format version
- `DeserializationException::missingField(string $field)` — required field absent from JSON
- `DeserializationException::unknownObjectId(string $objectId)` — no registered provider for an object
- `DeserializationException::classNotFound(string $className)` — JSON-serialized class no longer exists
- `DeserializationException::classNotJsonSerializable(string $className)` — class does not implement `JsonSerializableInterface`
- `DeserializationException::corruptStdClass()` — `stdClass` entry has wrong structure
- `DeserializationException::unknownValueType(string $raw)` — unknown type prefix in serialized string

---

## Catching all Procer errors

```php
use Karboosx\Procer\Exception\ProcerException;
use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Exception\DeserializationException;

try {
    $process = (new Deserializer())->deserialize($storedJson);
    $context = $procer->resume($process);
} catch (ParserException $e) {
    // Syntax error in source code
    return "Syntax error: " . $e->getMessage();
} catch (DeserializationException $e) {
    // Stored state is corrupt or from a different version
    return "Cannot restore saved state: " . $e->getMessage();
} catch (MaxCyclesException $e) {
    // Runaway script
    return "Script took too long.";
} catch (FunctionNotFoundException $e) {
    // Called a function that isn't registered
    return "Unknown function: " . $e->getFunctionName();
} catch (ProcerException $e) {
    // Anything else
    return "Runtime error: " . $e->getMessage();
}
```
