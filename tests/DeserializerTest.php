<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Serializer\DeserializeObjectProviderInterface;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\LazyDeserializer;
use Karboosx\Procer\Serializer\SerializableObjectInterface;
use PHPUnit\Framework\TestCase;

class DeserializerTest extends TestCase
{
    public function testDeserialize(): void
    {
        $deserializer = new Deserializer();

        $process = $deserializer->deserialize('{"s":[{"v":{"s:a":"i:1"},"s":[],"r":null,"p":null}],"c":0,"ic":{"i":[{"o":1,"a":{"i:0":"i:1"},"t":{"l":1,"p":10}},{"o":4,"a":{"i:0":"s:a"},"t":{"l":1,"p":0}}],"p":[]},"i":"i:2"}');

        self::assertInstanceOf(Process::class, $process);
    }

    public function testDeserializeLastInterrupt(): void
    {
        $deserializer = new Deserializer();

        $process = $deserializer->deserialize('{"s":[{"v":{"s:a":"i:1"},"s":[],"r":null,"p":null}],"c":0,"l":1,"ic":{"i":[{"o":1,"a":{"i:0":"i:1"},"t":{"l":1,"p":10}},{"o":4,"a":{"i:0":"s:a"},"t":{"l":1,"p":0}}],"p":[]},"i":"i:2"}');

        self::assertInstanceOf(Process::class, $process);
        self::assertInstanceOf(InterruptType::class, $process->lastInterruptType);
    }

    public function testLazyDeserialization(): void
    {
        $deserializer = new LazyDeserializer();

        $process = $deserializer->deserialize('{"v":1,"s":[{"v":{"s:a":"o:object_id"},"s":[],"r":null,"p":null}],"ic":{"i":[{"o":8,"a":[],"t":{"l":1,"p":0,"w":4}},{"o":2,"a":{"i:0":"s:a"},"t":{"l":1,"p":14,"w":9}},{"o":4,"a":{"i:0":"s:a"},"t":{"l":1,"p":5,"w":3}}],"p":[]},"i":"i:1","c":1,"l":null}');

        self::assertInstanceOf(Process::class, $process);

        $procer = new Procer();

        self::expectExceptionMessage('Object not found: object_id');
        $procer->resume($process);
    }

    public function testLazyDeserializationWithGettingTheData(): void
    {
        $provider = new class implements DeserializeObjectProviderInterface {

            public function supports(string $objectId): bool
            {
                return true;
            }

            public function deserialize(string $objectId): mixed
            {
                return new class implements SerializableObjectInterface {
                    public function getSerializeId(): string
                    {
                        return 'object_id';
                    }

                    public function test(): string
                    {
                        return 'test ok';
                    }
                };
            }
        };

        $deserializer = new LazyDeserializer($provider);

        $process = $deserializer->deserialize('{"v":1,"s":[{"v":{"s:a":"o:object_id"},"s":[],"r":null,"p":null}],"ic":{"i":[{"o":8,"a":[],"t":{"l":1,"p":0,"w":4}},{"o":2,"a":{"i:0":"s:a"},"t":{"l":1,"p":14,"w":9}},{"o":4,"a":{"i:0":"s:a"},"t":{"l":1,"p":5,"w":3}}],"p":[]},"i":"i:1","c":1,"l":null}');

        self::assertInstanceOf(Process::class, $process);

        $procer = new Procer();

        $context = $procer->resume($process);

        self::assertEquals('test ok', $context->get('a')->test());
    }
}
