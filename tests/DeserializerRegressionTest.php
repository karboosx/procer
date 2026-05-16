<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\Serializer;
use PHPUnit\Framework\TestCase;

class DeserializerRegressionTest extends TestCase
{
    public function testDeserializeRejectsUnknownInstructionOpcode(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['ic']['i'][0]['o'] = 999;

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('unknown instruction opcode');

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsJsonNullRoot(): void
    {
        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('root must be an object');

        (new Deserializer())->deserialize('null');
    }

    public function testDeserializeRejectsNonArrayInstructionArguments(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['ic']['i'][0]['a'] = 'not-an-array';

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('instruction arguments');

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsRawBooleanValue(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['s'][0]['v']['a'] = true;

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('unknown type prefix');

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsEmptyScopeList(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['s'] = [];

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage("field 's' must be a non-empty array");

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsInvalidCurrentInstructionIndex(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['i'] = 'd:1.5';

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage("field 'i' must be a non-negative serialized integer");

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsInvalidTokenInfoShape(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['ic']['i'][0]['t']['l'] = 'one';

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage("token field 'l' must be an integer");

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsInvalidInterruptType(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['l'] = 999;

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('unknown interrupt type');

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsInvalidStdClassJson(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['s'][0]['v']['a'] = 'os:{not-json';

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('stdClass');

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testDeserializeRejectsCorruptStdClassPropertyName(): void
    {
        $raw = $this->serializedProcessArray();
        $raw['s'][0]['v']['a'] = 'os:' . json_encode([[[1], 's:value']]);

        self::expectException(DeserializationException::class);
        self::expectExceptionMessage('stdClass');

        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testStringTypePrefixesRoundTripAsPlainStrings(): void
    {
        $serializer = new Serializer();
        $deserializer = new Deserializer();

        self::assertSame('i:123', $deserializer->deserializeValue($serializer->serializeValue('i:123')));
        self::assertSame('o:abc', $deserializer->deserializeValue($serializer->serializeValue('o:abc')));
        self::assertSame('j:Class:{}', $deserializer->deserializeValue($serializer->serializeValue('j:Class:{}')));
    }

    public function testProcedureProcessWithLocalArgumentInstructionRoundTrips(): void
    {
        $procer = new Procer();
        $context = $procer->run(<<<CODE
let value be 100.

procedure echo(value) do
    stop.
    return value.

let result be echo(5).
CODE);

        $process = (new Deserializer())->deserialize($context->serialize());
        $resumed = $procer->resume($process);

        self::assertSame(100, $resumed->get('value'));
        self::assertSame(5, $resumed->get('result'));
    }

    private function serializedProcessArray(): array
    {
        $context = (new Procer())->run('let a be 1. stop. let b be 2.');
        return json_decode($context->serialize(), true);
    }
}
