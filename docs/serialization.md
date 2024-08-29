# Serialization

Procer supports serialization and deserialization of the `Procer` object. This allows you to save the state of the `Procer` object and resume the execution of the code from where it was left off.

Here is an example of how to serialize and deserialize the `Procer` object:

```php
use Procer\Procer;
use Procer\Serializer\Deserializer;

$procer = new Procer();
$context = $procer->run('stop. let x be 10.');
$serialized = $context->serialize();

$deserializer = new Deserializer();
$processToResume = $deserializer->deserialize($serialized);

$procer = new Procer();
$result = $procer->resume($processToResume);
echo $result->get('x'); // 10
```

## Serializer

To serialize the `Procer` object, you can use the `serialize` method of the `Context` object. This method returns a string that can be used to recreate the `Procer` object.

```php
$context = $procer->run('...');
$serialized = $context->serialize();
```

### Serialize custom objects

If you have custom objects that you want to serialize, you can implement the `\Procer\Serializer\SerializableObjectInterface` interface for the custom object.
You need to implement the `getSerializeId` method that returns a unique identifier for the object that later can be used to deserialize the object by custom deserializer provider.

Here is an example of how to serialize a custom object:

```php
use Procer\Serializer\SerializableObjectInterface;

class User implements SerializableObjectInterface
{
    private $id;
    private $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
    
    public static function getSerializeId(): string
    {
        return 'user.'.$this->id;
    }
}
```

### Use of ObjectTypeId

For convenience, you can use the `ObjectTypeId` class to generate the unique identifier for the object.
This class generates a unique identifier based on the object type and the object id.

Here is an example of how to use the `ObjectTypeId` class:

```php
use Procer\Serializer\ObjectTypeId;

class User implements SerializableObjectInterface
{
    private $id;
    private $name;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }
    
    public static function getSerializeId(): string
    {
        return new ObjectTypeId('user', $this->id);
    }
}
```

## Deserializer

To deserialize the `Procer` object, you can use the `deserialize` method of the `Deserializer` object. This method takes a string that was created by the `serialize` method and returns a `Process` object that can be used to resume the execution of the code.

```php
$deserializer = new Deserializer();
$processToResume = $deserializer->deserialize($serialized);
```

## Deserialize custom objects

If you have custom objects that you want to serialize and deserialize, you can implement the `\Procer\Serializer\DeserializeObjectProviderInterface` interface and pass an instance of this class to the `Deserializer` constructor.

Here is an example of how to deserialize a custom object:

```php
use Procer\Procer;
use Procer\Serializer\Deserializer;
use Procer\Serializer\DeserializeObjectProviderInterface;

class CustomObjectProvider implements DeserializeObjectProviderInterface
{
    public function supports(string $objectId): bool
    {
        return strpos($objectId, 'user.') === 0;
    }

    public function deserialize(string $objectId): mixed
    {
        // Here you can actually fetch the object from the database or any other source
        
        $id = (int) str_replace('user.', '', $objectId);
        return new User($id, 'John Doe');
    }

}
```

You can pass an instance of the `CustomObjectProvider` class to the `Deserializer` constructor:

```php
$deserializer = new Deserializer([
    new CustomObjectProvider()
]);
```
