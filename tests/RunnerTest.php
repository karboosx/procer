<?php

namespace Procer\Tests;

use Procer\IC\ICParser;
use Procer\Parser\Parser;
use Procer\Parser\Tokenizer;
use Procer\Runner\Runner;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase
{
    /**
     * @dataProvider provideExpressions
     */
    public function testCorrectExpressions($expression, $values): void
    {
        $parser = new Parser(new Tokenizer());
        $ast = $parser->parse($expression);
        $icParser = new ICParser();
        $ic = $icParser->parse($ast);
        $runner = new Runner();
        $runner->loadCode($ic);
        $context = $runner->run();

        foreach ($values as $name => $value) {
            self::assertSame($value, $context->get($name));
        }
    }

    public function provideExpressions(): array
    {
        return [
            // Let
            ['let a be 1.', ['a' => 1]],
            ['let a be 1 + 1.', ['a' => 2]],

            // If
            ['if 1 < 2 do let a be 1. if not do let a be 2. done', ['a' => 1]],
            ['if 1 > 2 do let a be 1. if not do let a be 2. done', ['a' => 2]],
        ];
    }
}
