<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\IcParserException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class RuntimeEdgeCaseTest extends TestCase
{
    public function testBareObjectCallsWorkInsideExpressions(): void
    {
        $provider = new class implements ObjectFunctionProviderInterface {
            public function supports(object $object, string $functionName): bool { return $functionName === 'ready'; }
            public function ready(Context $context, object $object): bool { return true; }
        };
        $procer = new Procer([$provider]);
        foreach (['ready on obj', '(ready on obj) and true', 'on obj ready is true', 'on obj ready and (true)', 'on obj ready is (true)', 'on obj do ready', 'on obj do ready()'] as $source) {
            self::assertTrue($procer->runExpression($source, ['obj' => new \stdClass()]), $source);
        }
    }

    /** @dataProvider wrongProcedureArguments */
    public function testProcedureArgumentCountIsEnforced(string $call): void
    {
        $this->expectException(RunnerException::class);
        $this->expectExceptionMessage('Expected stack count');
        (new Procer())->run("procedure f(a) do\n    return a.\n$call.");
    }

    public static function wrongProcedureArguments(): array
    {
        return [['f()'], ['f(1, 2)']];
    }

    public function testParenthesizedConditionsAndReturnAreStatements(): void
    {
        $context = (new Procer())->run("let x be 0.\nwhile (x < 3) do\n    let x be x + 1.\nuntil (x = 4) do\n    let x be x + 1.\nreturn (x * 2).");
        self::assertSame(8, $context->getReturnValue());
    }

    public function testDocumentedLongNothingForm(): void
    {
        self::assertTrue((new Procer())->run('nothing do nothing.')->isFinished());
    }

    public function testSignalNamesCanMatchBuiltInValueNames(): void
    {
        $procer = new Procer();
        foreach (['true', 'false', 'null'] as $name) {
            self::assertTrue($procer->runExpression('signal is ' . $name, [], [$name]));
            self::assertFalse($procer->runExpression('signal is ' . $name));
        }
    }

    public function testProcedureArgumentsKeepTheirDeclaredOrder(): void
    {
        $context = (new Procer())->run("procedure digits(a, b, c) do\n    return a * 100 + b * 10 + c.\nreturn digits(1, 2, 3).");
        self::assertSame(123, $context->getReturnValue());
    }

    /** @dataProvider invalidArithmetic */
    public function testInvalidArithmeticIsAControlledError(string $expression, array $variables = []): void
    {
        $this->expectException(RunnerException::class);
        (new Procer())->runExpression($expression, $variables);
    }

    public static function invalidArithmetic(): array
    {
        return [
            ['1 % 0.5'], ['1 % -0.5'], ['"oops" * 2'], ['1 / "oops"'],
            ['a + 1', ['a' => []]], ['"x" + a', ['a' => []]],
            ['1 / a', ['a' => new \stdClass()]], ['a - 1', ['a' => new \stdClass()]],
            ['a * 2', ['a' => PHP_FLOAT_MAX]], ['a % 3', ['a' => PHP_FLOAT_MAX]],
        ];
    }

    public function testModuloTruncatesWithoutPhpDeprecations(): void
    {
        self::assertSame(1, (new Procer())->runExpression('5.9 % 2.1'));
    }

    public function testShortCircuitSkipsProviderSideEffects(): void
    {
        $provider = new class implements FunctionProviderInterface {
            public int $calls = 0;
            public function supports(string $functionName): bool { return $functionName === 'touch'; }
            public function touch(Context $context): bool { $this->calls++; return true; }
        };
        $procer = new Procer([$provider]);
        self::assertFalse($procer->runExpression('false and touch()'));
        self::assertTrue($procer->runExpression('true or touch()'));
        self::assertSame(0, $provider->calls);
        self::assertTrue($procer->runExpression('true and touch()'));
        self::assertSame(1, $provider->calls);
    }

    public function testNumericLiteralBoundaries(): void
    {
        $procer = new Procer();
        self::assertSame(PHP_INT_MAX, $procer->runExpression((string)PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, $procer->runExpression((string)PHP_INT_MIN));
        self::assertSame(123, $procer->runExpression('000123'));
        $this->expectException(IcParserException::class);
        $procer->runExpression((string)PHP_INT_MAX . '0');
    }

    public function testInfiniteDecimalLiteralIsRejected(): void
    {
        $this->expectException(IcParserException::class);
        (new Procer())->runExpression(str_repeat('9', 400) . '.5');
    }

    public function testZeroBudgetCannotCallProviderEvenAfterRepeatedResume(): void
    {
        $provider = new class implements FunctionProviderInterface {
            public int $calls = 0;
            public function supports(string $functionName): bool { return $functionName === 'touch'; }
            public function touch(Context $context): void { $this->calls++; }
        };
        $procer = new Procer([$provider]);
        $procer->setMaxCycles(0);
        for ($i = 0; $i < 3; $i++) {
            try {
                $i === 0 ? $procer->run('touch.') : $procer->resume();
                self::fail('Budget must reject before execution');
            } catch (MaxCyclesException) {
                self::assertSame(0, $provider->calls);
            }
        }
    }

    public function testDebugPrintingDoesNotReplacePausedProgram(): void
    {
        $procer = new Procer();
        $procer->run('let x be 1. stop. let x be 2.');
        $procer->printIcCode('let x be 100.');
        $procer->printIcExpression('99 + 1');
        self::assertSame(2, $procer->resume()->get('x'));
    }

    public function testResumeBeforeRunIsAControlledError(): void
    {
        $this->expectException(RunnerException::class);
        (new Procer())->resume();
    }

    public function testUninitializedPublicPropertyIsAControlledError(): void
    {
        $object = new class { public string $name; };
        $this->expectException(RunnerException::class);
        (new Procer())->runExpression('name of obj', ['obj' => $object]);
    }

    public function testForEachVisitsSparseAndAssociativeValues(): void
    {
        $context = (new Procer())->run("let sum be 0.\nfor each value in items do\n    let sum be sum + value.", ['items' => ['a' => 3, 5 => 4, 'b' => 7]]);
        self::assertSame(14, $context->get('sum'));
    }

    public function testDescendingRange(): void
    {
        $procer = new Procer();
        $procer->setMaxCycles(500);
        $context = $procer->run("let sum be 0.\nfrom 3 to 1 by -1 as i do\n    let sum be sum + i.");
        self::assertSame(6, $context->get('sum'));
    }

    public function testZeroRangeStepIsRejectedBeforeBody(): void
    {
        $procer = new Procer();
        $procer->setMaxCycles(100);
        $this->expectException(RunnerException::class);
        $this->expectExceptionMessage('step');
        $procer->run("from 1 to 2 by 0 do\n    nothing.");
    }
}
