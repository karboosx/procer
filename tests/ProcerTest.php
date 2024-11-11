<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;
use Karboosx\Procer\Tests\Helper\TestableFunctionProviderMock;
use Karboosx\Procer\Tests\Helper\TestableObjectFunctionProviderMock;

class ProcerTest extends TestCase
{
    /**
     * @dataProvider provideCodes
     */
    public function testCorrectCodes($code, $functionsAndValues, $values, $signals = []): void
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
        $procer->useDoneKeyword();

        $context = $procer->run($code, $variables, $signals);

        foreach ($values as $name => $value) {
            self::assertSame($value, $context->get($name));
        }
    }

    /**
     * @dataProvider provideExpressions
     */
    public function testCorrectExpressions($expression, $functionsAndValues, $expectedValue, $signals = []): void
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
        $procer->useDoneKeyword();

        $result = $procer->runExpression($expression, $variables, $signals);

        self::assertSame($expectedValue, $result);
    }

    public function provideCodes(): array
    {
        return [
            // Let
            ['let a be 1.', [], ['a' => 1]],
            ['let a be 1 + 1.', [], ['a' => 2]],
            ['let a be test().', [self::mock('test', [], "ok")], ['a' => "ok"]],
            ['let a be test2(1).', [self::mock('test2', [1], "ok")], ['a' => "ok"]],
            ['let a be test3(1,2).', [self::mock('test3', [1, 2], "ok")], ['a' => "ok"]],
            ['let a be "Hello, World!".', [], ['a' => "Hello, World!"]],
//            ['let a be true.', [], ['a' => true]],
//            ['let a be false.', [], ['a' => false]],
            ['let a be add(1, subtract(5, 3)).', [self::mock('add', [1, 2], 3), self::mock('subtract', [5, 3], 2)], ['a' => 3]],

            // If
            ['if true = true do let x be 1. done', [], ['x' => 1]],
            ['if true = false do let x be 1. if not do let x be 2. done', [], ['x' => 2]],
            ['if null = null do let x be 1. done', [], ['x' => 1]],
            ['if true != false do let x be 1. done', [], ['x' => 1]],
            ['if a > b do let x be 1. done', ['a' => 2, 'b' => 1], ['x' => 1]],
            ['if a < b do let x be 1. done', ['a' => 1, 'b' => 2], ['x' => 1]],
            ['if a < b do let x be 1. if not do let x be 2. done', ['a' => 2, 'b' => 1], ['x' => 2]],
            ['if a < b do let x be 1. if not do let x be 2. done', ['a' => 1, 'b' => 2], ['x' => 1]],
            ['if a < b do let x be "a < b". or a > b do let x be "a > b". if not do let x be "a = b". done', ['a' => 2, 'b' => 1], ['x' => 'a > b']],
            ['if a < b do let x be "a < b". or a > b do let x be "a > b". if not do let x be "a = b". done', ['a' => 1, 'b' => 2], ['x' => 'a < b']],
            ['if a < b do let x be "a < b". or a > b do let x be "a > b". if not do let x be "a = b". done', ['a' => 2, 'b' => 2], ['x' => 'a = b']],
            ['if a < b do let x be "nested condition". if not do if a > b do let x be "reverse". done done', ['a' => 1, 'b' => 2], ['x' => 'nested condition']],
            ['if a > 0 do if b > 0 do let c be a + b. if not do let c be "negative". done done', ['a' => 1, 'b' => -1], ['c' => 'negative']],
            ['if a > 0 do if b > 0 do let c be a + b. if not do let c be "negative". done done', ['a' => 1, 'b' => 2], ['c' => 3]],

            ['if not a do let x be 1. if not do let x be 2. done', ['a' => false], ['x' => 1]],
            ['if not a is not false do let x be 1. if not do let x be 2. done', ['a' => false], ['x' => 1]],
            ['if not a is true do let x be 1. if not do let x be 2. done', ['a' => false], ['x' => 1]],

//            // Loops
            ['let x be 0. from 1 to 3 do let x be x+1. done', [], ['x' => 3]],
            ['let x be 0. from 0 to 10 by 5 do let x be x+1. done', [], ['x' => 3]],
            ['let x be 0. from 0 to 10 by 5 as i do let x be x+i. done', [], ['x' => 15, 'i' => 15]],

            ['from 1 to 3 as i do let x be i. done', [], ['x' => 3]],
            ['let sum be 0. for each item in list() do let sum be sum + item. done', [self::mock('list', [], [1,2,3])], ['sum' => 6]],
            ['for each item in list do let x be item. done', ['list' => [1, 2, 3]], ['x' => 3]],
            ['let sum be 0. for each item in list do let sum be sum + item. done', ['list' => [1, 2, 3]], ['sum' => 6]],
            ['let product be 1. from 1 to 4 as i do let product be product * i. done', [], ['product' => 24]],
            ['let factorial be 1. from 1 to 5 as i do let factorial be factorial * i. done', [], ['factorial' => 120]],

            // While loop
            ['let x be 0. while x < 3 do let x be x + 1. done', [], ['x' => 3]],
            ['let x be 0. while x < 3 do let x be x + 1. while x < 5 do let x be x + 1. done done', [], ['x' => 5]],
            ['let x be 0. while x < 3 do let x be x + 1. done', [], ['x' => 3]],

            // Function Calls
            ['call_method().', [self::mock('call_method', [],)], []],
            ['set_value("value").', [self::mock('set_value', ["value"])], []],
            ['print("Hello", 124).', [self::mock('print', ["Hello", 124])], []],

            // Object function calls
            ['if call_method() on obj do let x be 1. done', ['obj' => new \stdClass(), self::mock('call_method', [], 1, 'obj')], ['x' => 1]],
            ['let x be 0. if call_method(true) on obj = 2 do let x be 1. done', ['obj' => new \stdClass(), self::mock('call_method', [], 1, 'obj')], ['x' => 0]],
            ['let x be 0. if call_method(true) on obj = 1 do let x be 1. done', ['obj' => new \stdClass(), self::mock('call_method', [], 1, 'obj')], ['x' => 1]],
            ['on obj call_method().', ['obj' => new \stdClass(), self::mock('call_method', [], null, 'obj')], []],
            ['on obj set_value("value").', ['obj' => new \stdClass(), self::mock('set_value', ["value"], null, 'obj')], []],
            ['on obj do print("Hello").', ['obj' => new \stdClass(), self::mock('print', ["Hello"], null, 'obj')], []],
            ['on obj run method().', ['obj' => new \stdClass(), self::mock('method', [], null, 'obj')], []],
            ['on obj run delete.', ['obj' => new \stdClass(), self::mock('delete', [], null, 'obj')], []],
            ['on shopping_cart add("apple").', ['shopping_cart' => new \stdClass(), self::mock('add', ["apple"], null, 'shopping_cart')], []],
            ['on user_account do logout.', ['user_account' => new \stdClass(), self::mock('logout', [], null, 'user_account')], []],
            ['on file run delete.', ['file' => new \stdClass(), self::mock('delete', [], null, 'file')], []],
            ['on user_account do confirm().', ['user_account' => new \stdClass(), self::mock('confirm', [], null, 'user_account')], []],
            ['on obj do complex_method(1, "arg").', ['obj' => new \stdClass(), self::mock('complex_method', [1, "arg"], null, 'obj')], []],

            ['call_method() on obj.', ['obj' => new \stdClass(), self::mock('call_method', [], null, 'obj')], []],
            ['set_value("value") on obj.', ['obj' => new \stdClass(), self::mock('set_value', ["value"], null, 'obj')], []],
            ['print("Hello") on obj.', ['obj' => new \stdClass(), self::mock('print', ["Hello"], null, 'obj')], []],
            ['method() on obj.', ['obj' => new \stdClass(), self::mock('method', [], null, 'obj')], []],
            ['delete on obj.', ['obj' => new \stdClass(), self::mock('delete', [], null, 'obj')], []],
            ['add("apple") on shopping_cart.', ['shopping_cart' => new \stdClass(), self::mock('add', ["apple"], null, 'shopping_cart')], []],
            ['logout on user_account.', ['user_account' => new \stdClass(), self::mock('logout', [], null, 'user_account')], []],
            ['delete on file.', ['file' => new \stdClass(), self::mock('delete', [], null, 'file')], []],
            ['confirm() on user_account.', ['user_account' => new \stdClass(), self::mock('confirm', [], null, 'user_account')], []],
            ['complex_method(1, "arg") on obj.', ['obj' => new \stdClass(), self::mock('complex_method', [1, "arg"], null, 'obj')], []],

            // Stop execution
            ['let x be 1. stop. let x be 2.', [], ['x' => 1]],
            // Nested blocks
            ['if x is 1 do if y is 2 do let z be 3. done done', ['x' => 1, 'y' => 2], ['z' => 3]],
            ['from 1 to 2 as i do from 1 to 2 as j do let x be i + j. done done', [], ['x' => 4]],
            ['if a < b do if c > d do let x be "complex condition". done done', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 2], ['x' => "complex condition"]],
            ['if a < b do if c > d do let x be "complex condition". if not do let x be "failed". done done', ['a' => 1, 'b' => 2, 'c' => 2, 'd' => 3], ['x' => "failed"]],
            ['let sum be 0. from 1 to 3 as i do from 1 to i as j do let sum be sum + 1. done done', [], ['sum' => 6]],
            ['if a is 1 do from 1 to 2 as i do let x be i * 2. done done', ['a' => 1], ['x' => 4]],

            // Expressions
            ['let a be 1 + 1.', [], ['a' => 2]],
            ['let a be 1 + 2 * 3.', [], ['a' => 7]],
            ['let a be (1 + 2) * 3.', [], ['a' => 9]],
            ['let a be 10 / 2 + 3.', [], ['a' => 8]],
            ['let a be 10 / (2 + 3).', [], ['a' => 2]],
            ['let a be 5 - 3 + 2.', [], ['a' => 4]],
            ['let a be 2 * 3 + 4 / 2.', [], ['a' => 8]],
            ['let a be 3 * (2 + 4).', [], ['a' => 18]],
            ['let a be (3 + 5) * (2 - 1).', [], ['a' => 8]],
            ['let a be 2 + 3 * 4 - 5.', [], ['a' => 9]],
            ['let a be (2 + 3) * (4 - 5) + 10.', [], ['a' => 5]],
            ['let a be 6 / 2 * (1 + 2).', [], ['a' => 9]],
            ['let a be b * 3 + func(2).', ['b' => 2, 'c' => 4, self::mock('func', [2], 4)], ['a' => 10]],
            ['let a be func(b + 1) * 3.', ['b' => 2, self::mock('func', [3], 3)], ['a' => 9]],
            ['let a be add(b, c) * 2.', ['b' => 2, 'c' => 3, self::mock('add', [2, 3], 5)], ['a' => 10]],
            ['let a be b + c * d.', ['b' => 1, 'c' => 2, 'd' => 3], ['a' => 7]],
            ['let a be b + func(c) - d.', ['b' => 10, 'c' => 4, 'd' => 5, self::mock('func', [4], 4)], ['a' => 9]],
            ['let a be func(b) + func(c).', ['b' => 1, 'c' => 1, self::mock('func', [1], 2)], ['a' => 4]],
            ['let a be b + c + func(d).', ['b' => 2, 'c' => 3, 'd' => 4, self::mock('func', [4], 5)], ['a' => 10]],

            // Expressions bool
            ['let a be 1 > 2.', [], ['a' => false]],
            ['let a be 1 > 2 or 1 = 1.', [], ['a' => true]],
            ['let a be 1 > 2 and 1 = 1.', [], ['a' => false]],
            ['let a be 1 is not 2.', [], ['a' => true]],
            ['let a be 1 is 2.', [], ['a' => false]],

            // Special Function call
            ['func.', ['b' => 2, 'c' => 3, 'd' => 4, self::mock('func', [])], []],
            ['confirm on user_account.', ['user_account' => new \stdClass(), self::mock('confirm', [], null, 'user_account')], []],
            ['let a be confirm on user_account.', ['user_account' => new \stdClass(), self::mock('confirm', [], 1, 'user_account')], ['a' => 1]],

            // Signals

            ['if signal is test do let a be 1. done', [], ['a' => 1], ['test']],
            ['let a be 0. if signal is not test do let a be 1. done', [], ['a' => 0], ['test']],
            ['let a be 0. if signal is test do let a be 1. done', [], ['a' => 0], []],

        ];
    }
    public function provideExpressions(): array
    {
        return [
            ['1', [], 1],
            ['null', [], null],
            ['false', [], false],
            ['true', [], true],
            ['1 + 1', [], 2],
            ['test()', [self::mock('test', [], "ok")],  "ok"],
            ['test2(1)', [self::mock('test2', [1], "ok")], "ok"],
            ['test3(1,2)', [self::mock('test3', [1, 2], "ok")], "ok"],
            ['"Hello, World!"', [], "Hello, World!"],
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

    public function testWaitForSignal()
    {
        $procer = new Procer();

        $output = $procer->run('let a be 0. wait for signal test. let a be 1.');
        self::assertSame(0, $output->get('a'));

        $procer->resume();
        self::assertSame(0, $output->get('a'));

        $procer->resume(null, [], ['test']);
        self::assertSame(1, $output->get('a'));
    }

    public function testWaitForTwoSignal()
    {
        $procer = new Procer();

        $output = $procer->run('let a be 0. wait for signal test,test2. let a be 1.');
        self::assertSame(0, $output->get('a'));

        $procer->resume();
        self::assertSame(0, $output->get('a'));

        $procer->resume(null, [], ['test']);
        self::assertSame(1, $output->get('a'));

        $output = $procer->run('let a be 0. wait for signal test,test2. let a be 1.');
        self::assertSame(0, $output->get('a'));

        $procer->resume();
        self::assertSame(0, $output->get('a'));

        $procer->resume(null, [], ['test2']);
        self::assertSame(1, $output->get('a'));
    }

    public function testOfAccess()
    {
        $procer = new Procer();

        $test = new class {
            public function test2()
            {
                return 556;
            }
        };

        $procer->addFunctionProvider(self::mock('print', [], 123));

        $output = $procer->runExpression('test2 of test', ['test'=>$test]);
        self::assertSame(556, $output);
        $output = $procer->runExpression('print(test2 of test)', ['test'=>$test]);
        self::assertSame(123, $output);
    }

    public function testOfAccessDeep()
    {
        $procer = new Procer();

        $testDeep = new class {
            public function test2()
            {
                return new class {
                    public function test3()
                    {
                        return 789;
                    }
                };
            }
        };

        $procer->addFunctionProvider(self::mock('print', [], 123));

        $output = $procer->runExpression('test3 of test2 of testDeep', ['testDeep'=>$testDeep]);
        self::assertSame(789, $output);
        $output = $procer->runExpression('print(test3 of test2 of testDeep)', ['testDeep'=>$testDeep]);
        self::assertSame(123, $output);
    }

    public function testProcedure()
    {
        $procer = new Procer();


        $code = <<<CODE
procedure add(a, b) do
    return a + b.

procedure x do
    return 10.

procedure change_c do
    let c be 3.

let a be add(1,2).
let b be x().

let c be 0.
change_c.
CODE;
        $output = $procer->run($code);

        self::assertSame(3, $output->get('a'));
        self::assertSame(10, $output->get('b'));
        self::assertSame(3, $output->get('c'));
    }
    public function testProcedureDontInterfereWithCode()
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
let a be 0.

procedure x do
    let a be 1.

CODE);

        self::assertSame(0, $output->get('a'));
    }

    public function testProcedureCanBeCalledBeforeDeclaration()
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
let a be x().

procedure x do
    let b be 2.
    let a be 1.
    return 5.

CODE);

        self::assertSame(5, $output->get('a'));
        self::assertFalse($output->has('b'));
    }

    public function testDoubleProcerRun()
    {
        $procer = new Procer();
        $output = $procer->runExpression('123');

        self::assertSame(123, $output);

        $output = $procer->runExpression('456');
        self::assertSame(456, $output);
    }

    public function testReturnFromMainScope()
    {
        $procer = new Procer();

        $output = $procer->run('return 123.');
        self::assertSame(123, $output->getReturnValue());
    }

    public function testWaitForSignalValueWithEndOfFileCheck()
    {
        $procer = new Procer();

        $output = $procer->run('wait for signal go.');

        self::assertSame(['go'], $output->getWaitForSignalValue());

        $output = $procer->resume(null, [], ['go']);

        self::assertNull($output->getWaitForSignalValue());
    }

    public function testWaitForSignalValue()
    {
        $procer = new Procer();

        $output = $procer->run('wait for signal go. let a be 1. wait for signal go_second.');

        self::assertSame(['go'], $output->getWaitForSignalValue());
        self::assertNull($output->get('a'));

        $output = $procer->resume(null, [], ['go']);

        self::assertSame(1, $output->get('a'));
        self::assertSame(['go_second'], $output->getWaitForSignalValue());
    }

    public function testWaitForSignals()
    {
        $procer = new Procer();

        $output = $procer->run('let a be 0. wait for signal testA,testB. let a be 1. wait for all signals testC, testD. let a be 2.');

        self::assertSame(['testA','testB'], $output->getWaitForSignalValue());
        self::assertSame(0, $output->get('a'));

        $output = $procer->resume(null, [], ['testA']);

        self::assertSame(1, $output->get('a'));
        self::assertSame(['testC','testD'], $output->getWaitForSignalValue());

        $output = $procer->resume(null, [], ['testC']);

        self::assertSame(1, $output->get('a'));
        self::assertSame(['testC','testD'], $output->getWaitForSignalValue());

        $output = $procer->resume(null, [], ['testD']);

        self::assertSame(1, $output->get('a'));
        self::assertSame(['testC','testD'], $output->getWaitForSignalValue());

        $output = $procer->resume(null, [], ['testC', 'testD']);

        self::assertSame(2, $output->get('a'));
        self::assertSame(null, $output->getWaitForSignalValue());

    }

    public function testMaxCycles()
    {
        self::expectException(MaxCyclesException::class);
        $procer = new Procer();
        $procer->setMaxCycles(10);

        $output = $procer->run('while 1 do');
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
