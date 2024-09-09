<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Serializer\Deserializer;
use PHPUnit\Framework\TestCase;

class DeserializerTest extends TestCase
{
    public function testDeserialize(): void
    {
        $deserializer = new Deserializer();

        $process = $deserializer->deserialize('{"s":[{"v":{"s:a":"i:1"},"s":[],"r":null,"p":null}],"ic":{"i":[{"o":1,"a":{"i:0":"i:1"},"t":{"l":1,"p":10}},{"o":4,"a":{"i:0":"s:a"},"t":{"l":1,"p":0}}],"p":[]},"i":"i:2"}');

        self::assertInstanceOf(Process::class, $process);

    }
}
