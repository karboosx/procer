<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\JsonSerializableInterface;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Serializer\DeserializeObjectProviderInterface;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\LazyDeserializer;
use Karboosx\Procer\Serializer\SerializableObjectInterface;
use PHPUnit\Framework\TestCase;

class DeserializerTest extends TestCase
{
    public function testDeserializeProducesProcess(): void
    {
        $dump = (new Procer())->run('let a be 1.')->serialize();

        $process = (new Deserializer())->deserialize($dump);

        self::assertInstanceOf(Process::class, $process);
        // 2 instructions: PUSH_VALUE + SET_VARIABLE
        self::assertSame(2, $process->cycles);
    }

    public function testDeserializePreservesVariables(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('let a be 42. let b be "hello". stop. let c be 99.');

        $dump = $context->serialize();

        $process = (new Deserializer())->deserialize($dump);
        $resumed = $procer->resume($process);

        self::assertSame(42, $resumed->get('a'));
        self::assertSame('hello', $resumed->get('b'));
        self::assertSame(99, $resumed->get('c'));
        self::assertTrue($resumed->isFinished());
    }

    public function testDeserializeRoundTripWithStop(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let x be 1. stop. let x be 2.');
        self::assertSame(1, $context->get('x'));
        self::assertFalse($context->isFinished());

        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        self::assertSame(2, $resumed->get('x'));
        self::assertTrue($resumed->isFinished());
    }

    public function testDeserializeRoundTripWithWaitForSignal(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let a be 0. wait for signal done. let a be 1.');
        self::assertSame(0, $context->get('a'));
        self::assertFalse($context->isFinished());

        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process, [], ['done']);

        self::assertSame(1, $resumed->get('a'));
        self::assertTrue($resumed->isFinished());
    }

    public function testDeserializeWithNumericTypes(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let i be 42. let f be 3.14. stop.');
        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        self::assertSame(42, $resumed->get('i'));
        self::assertSame(3.14, $resumed->get('f'));
    }

    public function testDeserializeWithBooleanValues(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let a be true. let b be false. stop.');
        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        self::assertSame(true, $resumed->get('a'));
        self::assertSame(false, $resumed->get('b'));
    }

    public function testDeserializeWithNullValue(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let a be null. stop.');
        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        self::assertNull($resumed->get('a'));
    }

    public function testDeserializeWithStdClassObject(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $obj = new \stdClass();
        $obj->name = 'Alice';
        $obj->age = 30;

        $context = $procer->run('stop.', ['user' => $obj]);

        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        $restored = $resumed->get('user');
        self::assertInstanceOf(\stdClass::class, $restored);
        self::assertSame('Alice', $restored->name);
        self::assertSame(30, $restored->age);
    }

    public function testDeserializeWithSerializableObject(): void
    {
        $serializableObj = new class implements SerializableObjectInterface {
            public function getSerializeId(): string
            {
                return 'my_object_123';
            }
        };

        $provider = new class implements DeserializeObjectProviderInterface {
            public function supports(string $objectId): bool
            {
                return $objectId === 'my_object_123';
            }

            public function deserialize(string $objectId): mixed
            {
                return new class implements SerializableObjectInterface {
                    public function getSerializeId(): string
                    {
                        return 'my_object_123';
                    }
                };
            }
        };

        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('stop.', ['obj' => $serializableObj]);

        $process = (new Deserializer($provider))->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        self::assertInstanceOf(SerializableObjectInterface::class, $resumed->get('obj'));
    }

    public function testDeserializeInvalidJsonThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('Cannot deserialize: invalid or corrupt JSON');

        (new Deserializer())->deserialize('not valid json {{{');
    }

    public function testLazyDeserializerWrapsObjectsInLazyObject(): void
    {
        $serializableObj = new class implements SerializableObjectInterface {
            public string $data = 'original';

            public function getSerializeId(): string
            {
                return 'lazy_obj_1';
            }
        };

        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('stop.', ['obj' => $serializableObj]);
        $dump = $context->serialize();

        $provider = new class implements DeserializeObjectProviderInterface {
            public function supports(string $objectId): bool
            {
                return $objectId === 'lazy_obj_1';
            }

            public function deserialize(string $objectId): mixed
            {
                $obj = new class implements SerializableObjectInterface {
                    public string $data = 'restored';

                    public function getSerializeId(): string
                    {
                        return 'lazy_obj_1';
                    }
                };
                return $obj;
            }
        };

        $process = (new LazyDeserializer($provider))->deserialize($dump);
        $resumed = $procer->resume($process);

        $restored = $resumed->get('obj');
        self::assertInstanceOf(SerializableObjectInterface::class, $restored);
        self::assertSame('restored', $restored->data);
    }

    public function testLazyDeserializerWithNoProviderThrows(): void
    {
        $serializableObj = new class implements SerializableObjectInterface {
            public function getSerializeId(): string
            {
                return 'unknown_obj';
            }
        };

        $procer = new Procer();
        $procer->useDoneKeyword();
        // The code accesses 'obj' after the stop so the lazy object is resolved on resume
        $context = $procer->run('stop. let x be obj.', ['obj' => $serializableObj]);
        $dump = $context->serialize();

        $process = (new LazyDeserializer())->deserialize($dump);

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage("Cannot deserialize object with ID 'unknown_obj'");

        $procer->resume($process);
    }

    public function testDeserializePreservesCycleCount(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let a be 1. let b be 2. stop.');
        $cyclesBefore = $context->getProcess()->cycles;

        $process = (new Deserializer())->deserialize($context->serialize());

        self::assertSame($cyclesBefore, $process->cycles);
    }

    public function testMultipleStopAndResumeRoundTrips(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();

        $context = $procer->run('let x be 1. stop. let x be 2. stop. let x be 3.');
        self::assertSame(1, $context->get('x'));

        $process = (new Deserializer())->deserialize($context->serialize());
        $context = $procer->resume($process);
        self::assertSame(2, $context->get('x'));

        $process = (new Deserializer())->deserialize($context->serialize());
        $context = $procer->resume($process);
        self::assertSame(3, $context->get('x'));
        self::assertTrue($context->isFinished());
    }

    // ── DeserializationException — all named constructors ────────────────────

    public function testVersionMismatchThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('format version');

        $json = json_encode(['v' => 99, 's' => [], 'ic' => [], 'i' => null, 'c' => 0]);
        (new Deserializer())->deserialize($json);
    }

    public function testMissingRequiredFieldThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage("required field");

        // Valid version but missing 's'
        $json = json_encode(['v' => 1]);
        (new Deserializer())->deserialize($json);
    }

    public function testUnknownValueTypeThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('unknown type prefix');

        // Build valid wrapper but inject a corrupt value string
        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('let a be 1. stop.');
        $raw = json_decode($context->serialize(), true);
        // Corrupt a variable value with an unknown type prefix
        $raw['s'][0]['v']['a'] = 'Z:corrupt';
        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testClassNotFoundThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage("class 'NonExistent\\Foo' does not exist");

        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('let a be 1. stop.');
        $raw = json_decode($context->serialize(), true);
        $raw['s'][0]['v']['a'] = 'j:NonExistent\\Foo:{}';
        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testClassNotJsonSerializableThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('does not implement JsonSerializableInterface');

        // stdClass exists but does not implement JsonSerializableInterface
        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('let a be 1. stop.');
        $raw = json_decode($context->serialize(), true);
        $raw['s'][0]['v']['a'] = 'j:stdClass:{}';
        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testCorruptStdClassThrows(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('stdClass');

        $procer = new Procer();
        $procer->useDoneKeyword();
        $context = $procer->run('let a be 1. stop.');
        $raw = json_decode($context->serialize(), true);
        // 'os:' prefix with a malformed pair (3 elements instead of 2)
        $raw['s'][0]['v']['a'] = 'os:' . json_encode([['key', 'value', 'extra']]);
        (new Deserializer())->deserialize(json_encode($raw));
    }
}
