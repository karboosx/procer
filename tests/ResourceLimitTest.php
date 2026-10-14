<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\Exception\SerializationException;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Serializer;
use PHPUnit\Framework\TestCase;

/** @group stress */
class ResourceLimitTest extends TestCase
{
    /** @dataProvider excessiveInputs */
    public function testExcessiveInputFailsWithParserException(string $source, bool $expression): void
    {
        $parser = new Parser(new Tokenizer(), true);
        $this->expectException(ParserException::class);
        $expression ? $parser->parseExpression($source) : $parser->parse($source);
    }

    public static function excessiveInputs(): iterable
    {
        yield 'bytes' => [str_repeat(' ', Tokenizer::MAX_SOURCE_BYTES + 1), false];
        yield 'tokens' => [str_repeat('nothing. ', 50001), false];
        yield 'parentheses' => [str_repeat('(', 5000) . '1' . str_repeat(')', 5000), true];
        yield 'unary minus' => [str_repeat('-', 5000) . '1', true];
        yield 'not' => [str_repeat('not ', 5000) . 'true', true];
        yield 'function arguments' => [str_repeat('f(', 5000) . '1' . str_repeat(')', 5000), true];
        yield 'left deep tree' => [implode('+', array_fill(0, 10000, '1')), true];
        yield 'blocks' => [str_repeat('if true do ', 5000) . 'nothing. ' . str_repeat('done ', 5000), false];
        yield 'branches' => ['if false do nothing. ' . str_repeat('or false do nothing. ', 5000) . 'done', false];
    }

    public function testLargeValidProgram(): void
    {
        $procer = new Procer();
        $procer->setMaxCycles(50000);
        $context = $procer->run('let x be 0. ' . str_repeat('let x be x + 1. ', 5000));
        self::assertSame(5000, $context->get('x'));
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }

    public function testModerateNestingStillWorks(): void
    {
        self::assertSame(42, (new Procer())->runExpression(str_repeat('(', 50) . '42' . str_repeat(')', 50)));
    }

    public function testWideParameterList(): void
    {
        $parameters = array_map(static fn(int $i) => 'arg' . $i, range(1, 10000));
        $root = (new Parser(new Tokenizer(), true))->parse('procedure wide(' . implode(',', $parameters) . ') do nothing. done');
        self::assertCount(10000, $root->statements[0]->arguments);
    }

    public function testRecursiveProcedureHasControlledDepthLimit(): void
    {
        $procer = new Procer();
        $procer->setMaxCycles(10000);
        $this->expectException(RunnerException::class);
        $this->expectExceptionMessage('call depth');
        $procer->run("procedure recurse do\n    recurse.\nrecurse.");
    }

    public function testCyclicValuesAreRejectedAndSerializerCanBeReused(): void
    {
        $serializer = new Serializer();
        $object = new \stdClass();
        $object->self = $object;
        $array = [];
        $array['self'] = &$array;
        foreach ([$object, $array] as $value) {
            try {
                $serializer->serializeValue($value);
                self::fail('Expected cyclic value rejection');
            } catch (SerializationException) {
                self::assertSame('i:42', $serializer->serializeValue(42));
            }
        }
    }

    public function testNestedObjectsCannotExpandJsonUntilMemoryIsExhausted(): void
    {
        $object = new \stdClass();
        for ($i = 0; $i < 40; $i++) {
            $object = (object)['child' => $object];
        }
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('size');
        (new Serializer())->serializeValue($object);
    }
}
