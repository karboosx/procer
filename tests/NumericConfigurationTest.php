<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\Serializer;
use PHPUnit\Framework\TestCase;

class NumericConfigurationTest extends TestCase
{
    /** @dataProvider precisionSettings */
    public function testSnapshotsPreserveFloatBitsRegardlessOfPhpPrecision(string $precision): void
    {
        $previous = ini_get('serialize_precision');
        $displayPrecision = ini_get('precision');
        try {
            ini_set('serialize_precision', $precision);
            ini_set('precision', '3');
            $serializer = new Serializer();
            $deserializer = new Deserializer();
            foreach ($this->finiteFloats() as $value) {
                $restored = $deserializer->deserializeValue($serializer->serializeValue($value));
                self::assertSame(bin2hex(pack('E', $value)), bin2hex(pack('E', $restored)), 'Float bits changed at precision=' . $precision);
            }
            $context = (new Procer())->run('stop. return number.', ['number' => PHP_FLOAT_MAX]);
            $context = (new Procer())->resume($deserializer->deserialize($context->serialize()));
            self::assertSame(PHP_FLOAT_MAX, $context->getReturnValue());
            self::assertSame($precision, ini_get('serialize_precision'));
            self::assertSame('3', ini_get('precision'));
        } finally {
            ini_set('serialize_precision', $previous);
            ini_set('precision', $displayPrecision);
        }
    }

    public static function precisionSettings(): array
    {
        return array_map(static fn($precision) => [$precision], ['-1', '0', '1', '3', '14', '17', '100']);
    }

    public function testFloatEncodingDoesNotDependOnNumericLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, 0);
        try {
            foreach (['C', 'pl_PL.UTF-8', 'Polish_Poland.1250', 'de_DE.UTF-8', 'German_Germany.1252'] as $locale) {
                if (setlocale(LC_NUMERIC, $locale) === false) { continue; }
                $value = 1.2345678901234567;
                $serialized = (new Serializer())->serializeValue($value);
                self::assertStringNotContainsString(',', $serialized, $locale);
                self::assertSame($value, (new Deserializer())->deserializeValue($serialized), $locale);
            }
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    private function finiteFloats(): iterable
    {
        yield from [0.0, -0.0, PHP_FLOAT_MAX, -PHP_FLOAT_MAX, PHP_FLOAT_MIN, -PHP_FLOAT_MIN, 5e-324, -5e-324, 1.2345678901234567];
        // Generate the IEEE-754 bit patterns directly, rather than sharing an
        // arithmetic/string conversion with the code under test.
        $state = 20261016;
        for ($i = 0; $i < 2000; $i++) {
            $high = $state = (1664525 * $state + 1013904223) & 0xffffffff;
            $low = $state = (1664525 * $state + 1013904223) & 0xffffffff;
            $value = unpack('Evalue', pack('N2', $high, $low))['value'];
            if (is_finite($value)) { yield $value; }
        }
    }
}
