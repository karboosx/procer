<?php

namespace Procer\Tests;

use Procer\Exception\FunctionNotFoundException;
use Procer\Procer;
use PHPUnit\Framework\TestCase;
use Procer\Tests\Helper\TestableFunctionProviderMock;

class ProcerTest extends TestCase
{
    /**
     * @dataProvider provideExpressions
     */
    public function testCorrectExpressions($code, $functionsAndValues, $values): void
    {
        $functions = [];
        $variables = [];
        foreach ($functionsAndValues as $key => $value) {
            if ($value instanceof TestableFunctionProviderMock) {
                $functions[] = $value;
            }else {
                $variables[$key] = $value;
            }
        }

        $procer = new Procer($functions);

        $context = $procer->run($code, $variables);

        foreach ($values as $name => $value) {
            self::assertSame($value, $context->get($name));
        }
    }

    public function provideExpressions(): array
    {
        return [
            // Let
            ['let a be 1.', [], ['a' => 1]],
            ['let a be 1 + 1.', [], ['a' => 2]],
            ['let a be test().', [self::mock('test', [], "ok")], ['a' => "ok"]],
            ['let a be test2(1).', [self::mock('test2', [1], "ok")], ['a' => "ok"]],
            ['let a be test3(1,2).', [self::mock('test3', [1, 2], "ok")], ['a' => "ok"]],

            // If
            ['if a > b do let x be 1. done', ['a' => 2, 'b' => 1], ['x' => 1]],
            ['if a < b do let x be 1. done', ['a' => 1, 'b' => 2], ['x' => 1]],
            ['if a < b do let x be 1. if not do let x be 2. done', ['a' => 2, 'b' => 1], ['x' => 2]],
            ['if a < b do let x be 1. if not do let x be 2. done', ['a' => 1, 'b' => 2], ['x' => 1]],
            ['if a < b do let x be "a < b". or a > b do let x be "a > b". if not do let x be "a = b". done', ['a' => 2, 'b' => 1], ['x' => 'a > b']],
            ['if a < b do let x be "a < b". or a > b do let x be "a > b". if not do let x be "a = b". done', ['a' => 1, 'b' => 2], ['x' => 'a < b']],
            ['if a < b do let x be "a < b". or a > b do let x be "a > b". if not do let x be "a = b". done', ['a' => 2, 'b' => 2], ['x' => 'a = b']],
        ];
    }


    public function testFunctionNotFound()
    {
        $procer = new Procer();

        self::expectException(FunctionNotFoundException::class);
        self::expectExceptionMessage('Function not found: test at line 1 position 9');

        $procer->run('let x be test().');

        $procer->addFunctionProvider(self::mock('test', [], "ok"));

        $context = $procer->resume();

        self::assertSame("ok", $context->get('x'));
    }

    private static function mock(
        string $name,
        array  $requiredArgs,
        mixed  $returnValue = null
    ): TestableFunctionProviderMock
    {
        return new TestableFunctionProviderMock($name, $requiredArgs, $returnValue);
    }
}
