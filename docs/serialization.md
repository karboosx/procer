# Saving and restoring a process

[Documentation index](README.md) · [PHP API](php_api.md)

`Context::serialize()` saves a **Process**, not the `Procer` interpreter or its PHP providers. `Deserializer::deserialize()` returns a `Karboosx\Procer\Runner\Process` that can be passed to a new interpreter's `resume()`.

## A complete round trip

<!-- verify:php {"output":"42"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;

$first = new Procer();
$first->setMaxCycles(1000);
$context = $first->run('let result be 42 as "value". stop. return result.');
$snapshot = $context->serialize();

// Store $snapshot in trusted application storage between requests.
$process = (new Deserializer())->deserialize($snapshot);
$second = new Procer();
$second->setMaxCycles(1000);
$context = $second->resume($process);
echo $context->getReturnValue()->value; // 42
```

Register any function/object providers needed by the remaining program on the new interpreter. A snapshot already contains compiled bytecode, so its original indentation/`done` parsing mode is not needed for resume.

## What is saved

| Saved in the process | Recreated or stored separately by the host |
| --- | --- |
| Compiled instructions and procedure pointers | Provider instances and their private state |
| Current instruction and procedure scopes | Cycle-budget configuration |
| Variables, pending operands, and return values | Signals for the next resume |
| Cumulative cycle count | Application authorization and service connections |
| Active steps and temporary handler bindings | Interrupt reason, requested signal names, and host-facing interrupt extra data |
| Last provider interrupt type | Source text, if needed for editing or diagnostics beyond stored token positions |

Persist host routing information alongside a snapshot before leaving a worker. `resume()` resets transient interruption information and determines the next state by executing the saved program.

Serialization does not consume the process. A failed serialization leaves it available to resume or retry saving after the unsupported value has been addressed.

## Snapshot versions

| Program instructions | Output format |
| --- | --- |
| Only instructions from the existing language | Version 1 |
| Contains record construction, named steps, or `next` | Version 2 |

The current reader accepts both versions. Older readers reject version 2. All workers that may restore new processes must support the new format before those processes are saved. Do not modify the version field manually.

Version 2 preserves active handler frames, saved bindings, and pending record values. The reader checks frame completeness, nesting, stack depths, instruction boundaries, and temporary bindings. The existing optional `e` scope field preserves the main script's external return value; older version 1 snapshots without it remain readable.

The format is executable VM state, not a user-editable document format. Structural validation is not an authorization boundary. See [Security](security.md#serialized-process-snapshots).

## Supported values

| PHP value | Behavior |
| --- | --- |
| null, bool, integer, string | Stored as typed values; JSON requires valid UTF-8 strings |
| Finite float | Stored with precision sufficient for a double round trip |
| Array | Keys and values preserved, including sparse/associative keys |
| `stdClass` / native Procer record | Fields recursively encoded without a custom provider |
| `SerializableObjectInterface` | Store its ID; restore through an application provider |
| Procer `JsonSerializableInterface` | Store class name and JSON; call its static `fromJson()` |
| Resource, unsupported object, cyclic or oversized value | `SerializationException` |

Every reachable value must be supported, including pending operands and a prior variable temporarily hidden by a handler. Native records do not make their contents automatically serializable.

The snapshot limit is 4 MiB, and serialized value depth is bounded at 128. Nested JSON object encodings can reach the size limit before the depth limit. Non-finite floats are rejected.

Ordinary object references are not encoded as a general identity graph. Repeated references may restore as separate objects. Use stable application IDs and an identity-aware loader when shared domain identity matters.

## Restore a domain object by ID

The complete example uses an in-memory object provider. An application can replace the lookup with authorized storage access.

<!-- verify:php {"output":"Ada"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\DeserializeObjectProviderInterface;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\ObjectTypeId;
use Karboosx\Procer\Serializer\SerializableObjectInterface;

final class Account implements SerializableObjectInterface
{
    public function __construct(public int $id, public string $name) {}
    public function getSerializeId(): string
    {
        return (string)new ObjectTypeId('account', $this->id);
    }
}

final class AccountObjects implements DeserializeObjectProviderInterface
{
    public function supports(string $objectId): bool
    {
        return str_starts_with($objectId, 'account:');
    }
    public function deserialize(string $objectId): mixed
    {
        $id = ObjectTypeId::fromString($objectId)->getId();
        return new Account($id, 'Ada');
    }
}

$context = (new Procer())->run('stop. return name of account.', [
    'account' => new Account(7, 'Ada'),
]);
$snapshot = $context->serialize();
$process = (new Deserializer(new AccountObjects()))->deserialize($snapshot);
echo (new Procer())->resume($process)->getReturnValue(); // Ada
```

`ObjectTypeId` uses `type:integer`, for example `account:7`. It provides `getType()`, `getId()`, `fromString()`, and `isTypeOf()`. Invalid encodings raise `DeserializationException`; the helper does not provide authorization or globally unique IDs by itself.

Pass multiple object providers as separate constructor arguments: `new Deserializer($accounts, $orders)`. The first provider supporting an ID performs its lookup. These are object-restoration providers, separate from function providers registered with `Procer`.

## Inline domain values

Implement **Procer's** `Karboosx\Procer\Serializer\JsonSerializableInterface` for small values that can restore themselves. PHP's built-in `JsonSerializable` alone is not this contract.

Class fragment:

```php
use Karboosx\Procer\Serializer\JsonSerializableInterface;

final class Money implements JsonSerializableInterface
{
    public function __construct(public int $amount, public string $currency) {}
    public function toJson(): string
    {
        return json_encode(['amount' => $this->amount, 'currency' => $this->currency], JSON_THROW_ON_ERROR);
    }
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return new self($data['amount'], $data['currency']);
    }
}
```

The class must be available to the receiving worker. Its `fromJson()` is application code and should validate data according to the application's contract. No object-ID provider is required. If an object implements both serialization interfaces, ID-based serialization is selected first.

## Lazy loading

`LazyDeserializer` accepts the same object providers as `Deserializer`, but defers ID-based object lookups. Procer resolves a `LazyObject` when it uses that value, including values already on an operand stack. Inline JSON objects are still restored through `fromJson()`.

```php
use Karboosx\Procer\Serializer\LazyDeserializer;

$process = (new LazyDeserializer($objectProvider))->deserialize($snapshot);
$context = $procer->resume($process);
```

A failing lazy loader leaves the unresolved operand available for a later host retry. If it throws `OperationFailure` while Procer is executing inside an applicable step, normal handler routing applies. An eager deserializer failure occurs before `resume()` and cannot enter script handlers.

Lazy loading is not recursive materialization of every returned record field. A record or array inspected directly by PHP may still contain `LazyObject` values. Prefer eager restoration when consumers require fully loaded values, or deliberately resolve lazy values through their `getRealObject()` method.

## Restore failures and deployment changes

Malformed JSON, unsupported versions, corrupt instructions/frames, missing classes, and unknown object IDs can prevent restore. Application loaders and serialization callbacks can also throw their own exceptions. Treat restore as a separate operation from executing a step.

Saved processes retain their old compiled program. Deploying a changed script or changing a provider's contract does not rewrite pending snapshots. Keep compatible providers available or migrate pending work deliberately. See [Migration](migration.md#saved-process-compatibility).
