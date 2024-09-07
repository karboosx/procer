<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;
use Karboosx\Procer\Tests\Helper\TestableFunctionProviderMock;
use Karboosx\Procer\Tests\Helper\TestableObjectFunctionProviderMock;

class ProcerTest extends TestCase
{
    /**
     * @dataProvider provideExpressions
     */
    public function testCorrectExpressions($code, $functionsAndValues, $values, $signals = []): void
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

    public function provideExpressions(): array
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

//            // Loops
            ['let x be 0. from 1 to 3 do let x be x+1. done', [], ['x' => 3]],
            ['let x be 0. from 0 to 10 by 5 do let x be x+1. done', [], ['x' => 3]],
            ['let x be 0. from 0 to 10 by 5 as i do let x be x+i. done', [], ['x' => 15, 'i' => 15]],

            ['from 1 to 3 as i do let x be i. done', [], ['x' => 3]],
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

            // Signals

            ['if signal is test do let a be 1. done', [], ['a' => 1], ['test']],
            ['let a be 0. if signal is test do let a be 1. done', [], ['a' => 0], []],

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
