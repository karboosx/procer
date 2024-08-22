<?php

namespace Procer\Tests;

use Procer\Runner\Process;
use Procer\Serializer\Deserializer;
use PHPUnit\Framework\TestCase;

class DeserializerTest extends TestCase
{
    public function testDeserialize(): void
    {
        $deserializer = new Deserializer();

        $process = $deserializer->deserialize('{"s":[{"v":{"s:a":"i:1"},"s":[]}],"ic":[{"o":1,"a":{"i:0":"i:1"},"t":{"l":1,"p":10}},{"o":4,"a":{"i:0":"s:a"},"t":{"l":1,"p":0}}],"i":"i:2"}');

        self::assertInstanceOf(Process::class, $process);

    }
}
