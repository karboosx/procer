<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\Exception\SerializationException;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\Serializer;
use Karboosx\Procer\Serializer\ObjectTypeId;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class SerializationRobustnessTest extends TestCase
{
    public function testObjectTypeIdsRoundTripAndRejectCorruption(): void
    {
        $id = ObjectTypeId::fromString('account:123');
        self::assertSame('account', $id->getType());
        self::assertSame(123, $id->getId());
        self::assertSame('account:123', (string)$id);
        self::assertTrue(ObjectTypeId::isTypeOf('account:123', 'account'));
        foreach (['', 'account', ':1', 'account:1junk', 'account:1:2', 'account:9999999999999999999999'] as $corrupt) {
            try {
                ObjectTypeId::fromString($corrupt);
                self::fail('Expected corrupt object ID rejection');
            } catch (DeserializationException $expected) {
                self::assertStringContainsString('type:integer', $expected->getMessage());
            }
        }
    }

    /** @dataProvider corruptSnapshots */
    public function testSnapshotStructureIsValidated(array $path, mixed $replacement): void
    {
        $snapshot = json_decode((new Procer())->run('let x be 1. stop.')->serialize(), true);
        $target = &$snapshot;
        foreach ($path as $part) {
            $target = &$target[$part];
        }
        $target = $replacement;
        $this->expectException(DeserializationException::class);
        (new Deserializer())->deserialize(json_encode($snapshot));
    }

    public static function corruptSnapshots(): iterable
    {
        yield [['i'], 'i:999'];
        yield [['i'], 'i:-1'];
        yield [['c'], -1];
        yield [['s'], ['named' => []]];
        yield [['s', 0, 'p'], -1];
        yield [['s', 0, 's'], ['named' => 'i:1']];
        yield [['ic', 'p'], ['f' => 'bad']];
        yield [['ic', 'p'], ['f' => -1]];
        yield [['ic', 'p'], ['f' => 999]];
        yield [['ic', 'i'], ['named' => []]];
        yield [['ic', 'i', 0, 'a'], []];
        yield [['ic', 'i', 0, 'a'], ['named' => 'i:1']];
        yield [['ic', 'i', 1, 'a'], ['i:1']];
        yield [['ic', 'i', 2, 'a'], ['i:1']];
        yield [['ic', 'i', 0], ['o' => 9, 'a' => ['i:999'], 't' => null]];
        yield [['ic', 'i', 0], ['o' => 9, 'a' => ['i:-1'], 't' => null]];
        yield [['ic', 'i', 0], ['o' => 3, 'a' => ['s:bad'], 't' => null]];
        yield [['ic', 'i', 0], ['o' => 19, 'a' => ['s:bad'], 't' => null]];
        yield [['ic', 'i', 0], ['o' => 12, 'a' => ['b:1'], 't' => null]];
        yield [['ic', 'i', 0, 't', 'l'], -1];
        yield [['ic', 'i', 0, 't', 'p'], -1];
        yield [['ic', 'i', 0, 't', 'w'], -1];
    }

    /** @dataProvider corruptValues */
    public function testCorruptTypedValuesAreRejected(mixed $value): void
    {
        $this->expectException(DeserializationException::class);
        (new Deserializer())->deserializeValue($value);
    }

    public static function corruptValues(): array
    {
        return array_map(static fn($value) => [$value], [
            'i:', 'i:1junk', 'i:1.5', 'i: 1', 'i:99999999999999999999999999999',
            'd:', 'd:NaN', 'd:INF', 'd:1e999', 'd:1junk', 'b:', 'b:true', 'b:2',
            'os:[{"x":"name","y":"s:value"}]', 'os:[["\u0000bad","s:value"]]',
        ]);
    }

    public function testFloatRoundTripsWithoutPrecisionLoss(): void
    {
        foreach ([1.2345678901234567, PHP_FLOAT_MAX, PHP_FLOAT_MIN, 0.0, -0.0, 1e-300] as $value) {
            self::assertSame($value, (new Deserializer())->deserializeValue((new Serializer())->serializeValue($value)));
        }
    }

    public function testNonFiniteFloatIsRejected(): void
    {
        $this->expectException(SerializationException::class);
        (new Serializer())->serializeValue(INF);
    }
}
