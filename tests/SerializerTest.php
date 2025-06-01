<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\JsonSerializableInterface;
use Karboosx\Procer\Serializer\Serializer;
use Karboosx\Procer\Tests\Helper\TestJsonSerializableObject;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    public function testSerializer(): void
    {
        $code = <<<CODE
stop.
let x be fund(a, func(b, func(c))).
CODE;

        $context = (new Procer())->run($code, [
            'account' => '1234567890',
            'a' => 1,
        ]);

        $serialize = $context->serialize();

        $expected = '{"v":1,"s":[{"v":{"account":"s:1234567890","a":"i:1"},"s":[],"r":null,"p":null}],"ic":{"i":[{"o":8,"a":[],"t":{"l":1,"p":0,"w":4}},{"o":2,"a":["s:c"],"t":{"l":2,"p":30,"w":1}},{"o":5,"a":["s:func","i:1","i:1"],"t":{"l":2,"p":25,"w":4}},{"o":13,"a":[],"t":{"l":2,"p":25,"w":4}},{"o":2,"a":["s:b"],"t":{"l":2,"p":22,"w":1}},{"o":5,"a":["s:func","i:2","i:1"],"t":{"l":2,"p":17,"w":4}},{"o":13,"a":[],"t":{"l":2,"p":17,"w":4}},{"o":2,"a":["s:a"],"t":{"l":2,"p":14,"w":1}},{"o":5,"a":["s:fund","i:2","i:1"],"t":{"l":2,"p":9,"w":4}},{"o":13,"a":[],"t":{"l":2,"p":9,"w":4}},{"o":4,"a":["s:x"],"t":{"l":2,"p":0,"w":3}}],"p":[]},"i":"i:1","c":1,"l":null}';

        self::assertSame($expected, $serialize);
    }

    public function testRandomClassSerializer() {
        $serializer = new Serializer();
        $serialized = $serializer->serializeValue(new TestJsonSerializableObject);

        $deserializer = new Deserializer();

        $deserialized = $deserializer->deserializeValue($serialized);

        self::assertInstanceOf(TestJsonSerializableObject::class, $deserialized);
        self::assertSame('dupa', $deserialized->property);
    }
}
