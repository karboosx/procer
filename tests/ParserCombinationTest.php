<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use PHPUnit\Framework\TestCase;

class ParserCombinationTest extends TestCase
{
    public function testNestedBranchTruthTableSurvivesWhitespaceAndCommentTransforms(): void
    {
        foreach ([false, true] as $outer) {
            foreach ([false, true] as $inner) {
                foreach ([false, true] as $alternative) {
                    $expected = $outer ? ($inner ? 1 : 2) : ($alternative ? 3 : 4);
                    $variables = compact('outer', 'inner', 'alternative');
                    foreach (["\n", "\r\n", "\r"] as $newline) {
                        foreach ([' ', '    ', "\t", " \t"] as $unit) {
                            foreach (['', '  '] as $base) {
                                $lines = [
                                    [0, 'let value be 0.'],
                                    [0, 'if outer do'],
                                    [1, 'if inner do'],
                                    [2, 'let value be 1.'],
                                    [1, 'if not do'],
                                    [2, 'let value be 2.'],
                                    [0, 'or alternative do'],
                                    [1, 'let value be 3.'],
                                    [0, 'if not do'],
                                    [1, 'let value be 4.'],
                                    [0, 'return value.'],
                                ];
                                $source = '';
                                foreach ($lines as [$depth, $line]) {
                                    $source .= $base . str_repeat($unit, $depth) . $line . ' // done or if not "' . $newline;
                                    $source .= $newline . $unit . '// comment indentation is irrelevant' . $newline;
                                }
                                self::assertSame($expected, (new Procer())->run($source, $variables)->getReturnValue(), json_encode($source));
                            }
                        }
                    }
                    $done = new Procer();
                    $done->useDoneKeyword();
                    self::assertSame($expected, $done->run('let value be 0. if outer do if inner do let value be 1. if not do let value be 2. done or alternative do let value be 3. if not do let value be 4. done return value.', $variables)->getReturnValue());
                }
            }
        }
    }

    public function testEveryByteInsideAQuotedStringPreservesItsValue(): void
    {
        $procer = new Procer();
        for ($byte = 0; $byte < 256; $byte++) {
            $value = 'before' . chr($byte) . 'after // if not done.';
            self::assertSame($value, $procer->runExpression($this->quote($value)), 'byte=' . $byte);
            self::assertSame($value, $procer->run('return ' . $this->quote($value) . '.')->getReturnValue(), 'byte=' . $byte);
        }
    }

    public function testEscapesAndUnicodeSurviveParseAndSnapshotRoundTrips(): void
    {
        $values = ["\0\r\n\t", 'Zażółć gęślą jaźń 😀 中文', "first\rsecond\nthird\r\nfourth", '// done. if not do "quoted"'];
        for ($length = 0; $length <= 32; $length++) {
            $slashes = str_repeat('\\', $length);
            $values[] = $slashes;
            $values[] = $slashes . '"' . $slashes . 'end';
            $values[] = 'prefix"' . $slashes;
        }
        foreach ($values as $value) {
            $source = 'let text be ' . $this->quote($value) . '. stop. return text.';
            $context = (new Procer())->run($source);
            self::assertSame($value, $context->get('text'), bin2hex($value));
            $context = (new Procer())->resume((new Deserializer())->deserialize($context->serialize()));
            self::assertSame($value, $context->getReturnValue(), bin2hex($value));
        }
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
