<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;
use Karboosx\Procer\Tests\Helper\TestableFunctionProviderMock;
use Karboosx\Procer\Tests\Helper\TestableObjectFunctionProviderMock;

class ProcerNoDoneKeywordTest extends TestCase
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
            } elseif ($value instanceof TestableObjectFunctionProviderMock) {
                $functions[] = $value;
            } else {
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
            [<<<CODE
let a be 1.
while a < 10 do
    let a be a + 1.

CODE, [], ['a' => 10]],
            [<<<CODE
let a be 1.
if a < 10 do
    let a be 4.
CODE, [], ['a' => 4]],
            [<<<CODE
let a be 1.
if a < 0 do
    let a be 4.
if not do
    let a be 5.

CODE, [], ['a' => 5]]
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
        string  $name,
        array   $requiredArgs,
        mixed   $returnValue = null,
        ?string $objectName = null
    ): TestableObjectFunctionProviderMock|TestableFunctionProviderMock
    {
        if ($objectName) {
            return new TestableObjectFunctionProviderMock($name, $requiredArgs, $returnValue, $objectName);
        }

        return new TestableFunctionProviderMock($name, $requiredArgs, $returnValue);
    }
}
