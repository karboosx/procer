<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\ObjectFunctionNotFoundException;
use Karboosx\Procer\Exception\PropertyNotFoundException;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\Exception\SerializationException;
use Karboosx\Procer\Exception\VariableNotFoundException;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\SerializableObjectInterface;
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
     * @dataProvider provideCodesWithReturn
     */
    public function testCorrectCodesByReturn($code, $functionsAndValues, $valueToCheck, $signals = []): void
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

        self::assertSame($valueToCheck, $context->getReturnValue());
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
            ['let a be true.', [], ['a' => true]],
            ['let a be false.', [], ['a' => false]],
            ['let a be null.', [], ['a' => null]],
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

            // Expressions concat
            ['let a be b + c.', ['b' => 'bb', 'c' => 'cc'], ['a' => 'bbcc']],
            ['let a be b + c.', ['b' => 'bb', 'c' => 3], ['a' => 'bb3']],

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

            // Exists
            ['let a be b exists.', ['b' => 1], ['a' => true]],
            ['let a be b not exists.', ['b' => 1], ['a' => false]],
            ['let a be b exists.', [], ['a' => false]],
            ['let a be b not exists.', [], ['a' => true]],
        ];
    }

    public function provideCodesWithReturn(): array
    {
        return [
            ['let a be 1. return a + b.', ['b' => 1], 2],
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
        self::expectExceptionMessage("Function 'test' is not defined");

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
        self::assertSame(false, $output->isFinished());

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

    // ── Operator / arithmetic edge cases ──────────────────────────────────────

    public function testDivisionByZeroThrows(): void
    {
        self::expectException(RunnerException::class);
        self::expectExceptionMessage('Division by zero');

        $procer = new Procer();
        $procer->run('let a be 1 / 0.');
    }

    public function testModuloByZeroThrows(): void
    {
        self::expectException(RunnerException::class);
        self::expectExceptionMessage('Division by zero');

        $procer = new Procer();
        $procer->run('let a be 5 % 0.');
    }

    public function testModuloOperator(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be 10 % 3.');
        self::assertSame(1, $output->get('a'));
    }

    public function testFloatArithmetic(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be 1.5 + 2.5. let b be 10.0 / 4.0. let c be 0.1 + 0.2.');
        self::assertSame(4.0, $output->get('a'));
        self::assertSame(2.5, $output->get('b'));
        self::assertEqualsWithDelta(0.3, $output->get('c'), 1e-9);
    }

    // ── Null-variable handling ────────────────────────────────────────────────

    public function testNullVariableIsDetectedByExists(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let x be null. let a be x exists. let b be x not exists.');
        self::assertSame(true, $output->get('a'));
        self::assertSame(false, $output->get('b'));
    }

    public function testNullVariableCanBeReadBack(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let x be null. let a be x.');
        self::assertNull($output->get('a'));
    }

    public function testGlobalNullVariablePassedIn(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be x exists.', ['x' => null]);
        self::assertSame(true, $output->get('a'));
    }

    // ── Object access (of / property / method) ────────────────────────────────

    public function testObjectPublicPropertyAccess(): void
    {
        $procer = new Procer();
        $obj = new \stdClass();
        $obj->score = 99;
        $output = $procer->run('let a be score of obj.', ['obj' => $obj]);
        self::assertSame(99, $output->get('a'));
    }

    public function testObjectGetterAccess(): void
    {
        $procer = new Procer();
        $obj = new class {
            public function getName(): string { return 'Bob'; }
        };
        $output = $procer->run('let a be name of obj.', ['obj' => $obj]);
        self::assertSame('Bob', $output->get('a'));
    }

    public function testObjectIsMethodAccess(): void
    {
        $procer = new Procer();
        $obj = new class {
            public function isActive(): bool { return true; }
        };
        $output = $procer->run('let a be active of obj.', ['obj' => $obj]);
        self::assertSame(true, $output->get('a'));
    }

    public function testObjectHasMethodAccess(): void
    {
        $procer = new Procer();
        $obj = new class {
            public function hasItems(): bool { return false; }
        };
        $output = $procer->run('let a be items of obj.', ['obj' => $obj]);
        self::assertSame(false, $output->get('a'));
    }

    public function testObjectAccessOnNonObjectThrows(): void
    {
        self::expectException(RunnerException::class);
        self::expectExceptionMessage("Cannot access property 'foo' on a non-object value");

        $procer = new Procer();
        $procer->run('let a be foo of x.', ['x' => 42]);
    }

    public function testObjectMissingPropertyThrows(): void
    {
        self::expectException(PropertyNotFoundException::class);
        self::expectExceptionMessage("Property or method 'missing' not found on object of type");

        $procer = new Procer();
        $obj = new class {};
        $procer->run('let a be missing of obj.', ['obj' => $obj]);
    }

    // ── Procedures ────────────────────────────────────────────────────────────

    public function testProcedureWithNoArgs(): void
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
procedure greet do
    return "hello".

let msg be greet().
CODE);
        self::assertSame('hello', $output->get('msg'));
    }

    public function testProcedureModifiesGlobal(): void
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
let counter be 0.

procedure increment do
    let counter be counter + 1.

increment.
increment.
increment.
CODE);
        self::assertSame(3, $output->get('counter'));
    }

    public function testNestedProcedureCalls(): void
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
procedure double(n) do
    return n * 2.

procedure quad(n) do
    return double(double(n)).

let a be quad(3).
CODE);
        self::assertSame(12, $output->get('a'));
    }

    public function testProcedureLocalVariableDoesNotLeakToGlobal(): void
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
procedure do_stuff do
    let local_var be 42.

do_stuff.
CODE);
        self::assertFalse($output->has('local_var'));
    }

    public function testRecursiveProcedure(): void
    {
        $procer = new Procer();
        $output = $procer->run(<<<CODE
procedure factorial(n) do
    if n <= 1 do
        return 1.
    if not do
        return n * factorial(n - 1).

let result be factorial(5).
CODE);
        self::assertSame(120, $output->get('result'));
    }

    // ── Error messages carry source location ─────────────────────────────────

    public function testVariableNotFoundIncludesLocation(): void
    {
        self::expectException(VariableNotFoundException::class);
        self::expectExceptionMessage("Variable 'missing_var' is not defined");

        $procer = new Procer();
        $procer->run('let a be missing_var.');
    }

    public function testFunctionNotFoundIncludesLocation(): void
    {
        self::expectException(FunctionNotFoundException::class);
        self::expectExceptionMessage('line 1');

        $procer = new Procer();
        $procer->run('let x be unknown_function().');
    }

    // ── String edge cases ─────────────────────────────────────────────────────

    public function testStringConcatWithNumber(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be "count: " + 42.');
        self::assertSame('count: 42', $output->get('a'));
    }

    public function testStringConcatWithBool(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be "val: " + true.');
        self::assertSame('val: 1', $output->get('a'));
    }

    public function testEmptyString(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be "".');
        self::assertSame('', $output->get('a'));
    }

    // ── Comparison / logic edge cases ─────────────────────────────────────────

    public function testIsOperatorWithSameTypeTrue(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let a be 1 is 1. let b be "x" is "x".');
        self::assertSame(true, $output->get('a'));
        self::assertSame(true, $output->get('b'));
    }

    public function testIsOperatorTypeMismatch(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        // loose == so "1" == 1 is true in PHP
        $output = $procer->run('let a be 0 is false.');
        self::assertSame(true, $output->get('a'));
    }

    public function testLogicalOperators(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run(
            'let a be true and false. let b be true or false. let c be false and false.'
        );
        self::assertSame(false, $output->get('a'));
        self::assertSame(true, $output->get('b'));
        self::assertSame(false, $output->get('c'));
    }

    // ── Return from main scope ────────────────────────────────────────────────

    public function testReturnFromMainScopeWithExpression(): void
    {
        $procer = new Procer();
        $output = $procer->run('let a be 5. return a * 2.');
        self::assertSame(10, $output->getReturnValue());
    }

    public function testStopHaltsExecution(): void
    {
        $procer = new Procer();
        $output = $procer->run('let a be 1. stop. let a be 99.');
        self::assertSame(1, $output->get('a'));
        self::assertFalse($output->isFinished());
    }

    // ── Until loop ────────────────────────────────────────────────────────────

    public function testUntilLoopRunsUntilConditionBecomesTrue(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        // 'until x >= 3' means: keep looping UNTIL x >= 3 (i.e. while x < 3)
        $output = $procer->run('let x be 0. until x >= 3 do let x be x + 1. done');
        self::assertSame(3, $output->get('x'));
    }

    public function testUntilLoopDoesNotRunWhenConditionAlreadyTrue(): void
    {
        $procer = new Procer();
        $procer->useDoneKeyword();
        $output = $procer->run('let x be 5. until x >= 3 do let x be x + 1. done');
        // condition is already true (5 >= 3), so the loop body never runs
        self::assertSame(5, $output->get('x'));
    }

    // ── NoDoneKeyword mode ───────────────────────────────────────────────────

    public function testNoDoneKeywordMode(): void
    {
        $procer = new Procer();
        // useDoneKeyword is false by default
        $output = $procer->run("let x be 0.\nfrom 1 to 3 do\n    let x be x + 1.");
        self::assertSame(3, $output->get('x'));
    }

    // ── ObjectFunctionNotFoundException ──────────────────────────────────────

    public function testObjectFunctionOnNonObjectThrows(): void
    {
        self::expectException(ObjectFunctionNotFoundException::class);
        self::expectExceptionMessage("Cannot call 'do_thing' on variable 'x': expected an object");

        $procer = new Procer();
        $procer->run('do_thing() on x.', ['x' => 42]);
    }

    public function testObjectFunctionMethodNotFoundThrows(): void
    {
        self::expectException(ObjectFunctionNotFoundException::class);
        self::expectExceptionMessage("Method 'do_thing' not found for object of type 'stdClass'");

        $procer = new Procer();
        $obj = new \stdClass();
        $procer->run('do_thing() on obj.', ['obj' => $obj]);
    }

    // ── VariableNotFoundException ─────────────────────────────────────────────

    public function testVariableNotFoundExceptionClass(): void
    {
        self::expectException(VariableNotFoundException::class);

        $procer = new Procer();
        $procer->run('let a be undefined_var.');
    }

    public function testVariableNotFoundExceptionGetVariableName(): void
    {
        $procer = new Procer();
        try {
            $procer->run('let a be my_missing_var.');
            self::fail('Expected VariableNotFoundException');
        } catch (VariableNotFoundException $e) {
            self::assertSame('my_missing_var', $e->getVariableName());
        }
    }

    // ── PropertyNotFoundException ─────────────────────────────────────────────

    public function testPropertyNotFoundExceptionGetters(): void
    {
        $procer = new Procer();
        $obj = new \stdClass();
        try {
            $procer->run('let a be gone of obj.', ['obj' => $obj]);
            self::fail('Expected PropertyNotFoundException');
        } catch (PropertyNotFoundException $e) {
            self::assertSame('gone', $e->getPropertyName());
            self::assertSame('stdClass', $e->getObjectClass());
        }
    }

    // ── SerializationException ────────────────────────────────────────────────

    public function testSerializeUnsupportedObjectThrows(): void
    {
        self::expectException(SerializationException::class);
        self::expectExceptionMessage("Cannot serialize object of class");

        $procer = new Procer();
        $procer->useDoneKeyword();

        // Assign a plain PHP object (no serialization interface) and pause
        $plain = new \SplStack();
        $context = $procer->run('stop.', ['obj' => $plain]);
        $context->serialize();
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
