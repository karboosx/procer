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
//    public function testDeserialize(): void
//    {
//        $dump = (new Procer())->run('let a be 1.')->serialize();
//
//        $deserializer = new Deserializer();
//
//        $process = $deserializer->deserialize($dump);
//
//        self::assertInstanceOf(Process::class, $process);
//    }
//
//    public function testDeserializeLastInterrupt(): void
//    {
//        $dump = (new Procer())->run('let a be 1. return a.')->serialize();
//
//        $deserializer = new Deserializer();
//
//        $process = $deserializer->deserialize($dump);
//
//        self::assertInstanceOf(Process::class, $process);
//        self::assertInstanceOf(InterruptType::class, $process->lastInterruptType);
//    }
//
//    public function testLazyDeserialization(): void
//    {
//        $dump = (new Procer())->run('return test on object.', ['object' => new class {}])->serialize();
//
//        $deserializer = new LazyDeserializer();
//
//        $process = $deserializer->deserialize($dump);
//
//        self::assertInstanceOf(Process::class, $process);
//
//        $procer = new Procer();
//
//        self::expectExceptionMessage('Object not found: object_id');
//        $procer->resume($process);
//    }
//
//    public function testLazyDeserializationWithGettingTheData(): void
//    {
//        $provider = new class implements DeserializeObjectProviderInterface {
//
//            public function supports(string $objectId): bool
//            {
//                return true;
//            }
//
//            public function deserialize(string $objectId): mixed
//            {
//                return new class implements SerializableObjectInterface {
//                    public function getSerializeId(): string
//                    {
//                        return 'object_id';
//                    }
//
//                    public function test(): string
//                    {
//                        return 'test ok';
//                    }
//                };
//            }
//        };
//
//        $dump = (new Procer())->run('return test on object_id.')->serialize();
//
//        $deserializer = new LazyDeserializer($provider);
//
//        $process = $deserializer->deserialize($dump);
//
//        self::assertInstanceOf(Process::class, $process);
//
//        $procer = new Procer();
//
//        $context = $procer->resume($process);
//
//        self::assertEquals('test ok', $context->get('a')->test());
//    }
}
