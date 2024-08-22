<?php

namespace Procer\Tests;

use Procer\Procer;
use Procer\Serializer\Serializer;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    public function testSerializer(): void
    {
        $deserializer = new Serializer();

        $code = <<<CODE
stop.
let x be fund(a, func(b, func(c))).
CODE;

        $context = (new Procer())->run($code, [
            'account' => '1234567890',
            'a' => 1,
        ]);

        $serialize = $deserializer->serialize($context);

        $expected = '{"v":1,"s":[{"v":{"s:account":"s:1234567890","s:a":"i:1"},"s":[]}],"ic":[{"o":8,"a":[],"t":{"l":1,"p":0}},{"o":2,"a":{"i:0":"s:c"},"t":{"l":2,"p":30}},{"o":5,"a":{"i:0":"s:func","i:1":"i:1","i:2":"i:1"},"t":{"l":2,"p":25}},{"o":2,"a":{"i:0":"s:b"},"t":{"l":2,"p":22}},{"o":5,"a":{"i:0":"s:func","i:1":"i:2","i:2":"i:1"},"t":{"l":2,"p":17}},{"o":2,"a":{"i:0":"s:a"},"t":{"l":2,"p":14}},{"o":5,"a":{"i:0":"s:fund","i:1":"i:2","i:2":"i:1"},"t":{"l":2,"p":9}},{"o":4,"a":{"i:0":"s:x"},"t":{"l":2,"p":0}}],"i":"i:1"}';

        self::assertSame($expected, $serialize);

    }
}
