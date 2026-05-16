<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\PropertyNotFoundException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptReason;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class RunnerRegressionTest extends TestCase
{
    public function testAfterExecutionInterruptValueIsUsedWhenExpressionResumes(): void
    {
        $provider = new class implements FunctionProviderInterface {
            public int $calls = 0;

            public function ask(Context $context): Interrupt
            {
                $this->calls++;
                return new Interrupt(InterruptType::AFTER_EXECUTION, 'done', 'php-data');
            }

            public function supports(string $functionName): bool
            {
                return $functionName === 'ask';
            }
        };

        $procer = new Procer([$provider]);
        $context = $procer->run('let x be ask(). let y be x + "!" .');

        self::assertFalse($context->isFinished());
        self::assertSame(InterruptReason::FUNCTION_REQUEST, $context->getInterruptReason());
        self::assertSame('php-data', $context->getInterruptData());
        self::assertSame(1, $provider->calls);
        self::assertNull($context->get('x'));

        $context = $procer->resume();

        self::assertSame(1, $provider->calls);
        self::assertSame('done', $context->get('x'));
        self::assertSame('done!', $context->get('y'));
        self::assertTrue($context->isFinished());
    }

    public function testAfterExecutionInterruptFromObjectFunctionIsUsedWhenExpressionResumes(): void
    {
        $provider = new class implements ObjectFunctionProviderInterface {
            public int $calls = 0;

            public function fetch(Context $context, object $object): Interrupt
            {
                $this->calls++;
                return new Interrupt(InterruptType::AFTER_EXECUTION, 'object-result');
            }

            public function supports(object $object, string $functionName): bool
            {
                return $functionName === 'fetch';
            }
        };

        $procer = new Procer([$provider]);
        $context = $procer->run('let x be fetch() on obj. let y be x + "-ok".', ['obj' => new \stdClass()]);

        self::assertFalse($context->isFinished());
        self::assertSame(1, $provider->calls);

        $context = $procer->resume();

        self::assertSame(1, $provider->calls);
        self::assertSame('object-result', $context->get('x'));
        self::assertSame('object-result-ok', $context->get('y'));
    }

    public function testAfterExecutionInterruptDoesNotPolluteStack(): void
    {
        $provider = new class implements FunctionProviderInterface {
            public function touch(Context $context): Interrupt
            {
                return new Interrupt(InterruptType::AFTER_EXECUTION, 'ignored');
            }

            public function supports(string $functionName): bool
            {
                return $functionName === 'touch';
            }
        };

        $procer = new Procer([$provider]);
        $context = $procer->run(<<<CODE
touch().
let x be 1.
CODE);

        self::assertFalse($context->isFinished());

        $context = $procer->resume();

        self::assertSame(1, $context->get('x'));
        self::assertSame([], $context->getProcess()->scopes[0]->getStack());
    }

    public function testProcedureArgumentDoesNotOverwriteGlobalWithSameName(): void
    {
        $context = (new Procer())->run(<<<CODE
let value be 100.

procedure echo(value) do
    return value.

let result be echo(5).
CODE);

        self::assertSame(100, $context->get('value'));
        self::assertSame(5, $context->get('result'));
    }

    public function testProcedureLetUpdatesLocalArgumentBeforeGlobal(): void
    {
        $context = (new Procer())->run(<<<CODE
let value be 100.

procedure increment(value) do
    let value be value + 1.
    return value.

let result be increment(5).
CODE);

        self::assertSame(100, $context->get('value'));
        self::assertSame(6, $context->get('result'));
    }

    public function testProcedureFallthroughReturnsNullAfterPreviousReturnValue(): void
    {
        $context = (new Procer())->run(<<<CODE
procedure one do
    return 1.

procedure none do
    nothing.

let a be one().
let b be none().
CODE);

        self::assertSame(1, $context->get('a'));
        self::assertNull($context->get('b'));
    }

    public function testProcedureReturnNothingReturnsNullAfterPreviousReturnValue(): void
    {
        $context = (new Procer())->run(<<<CODE
procedure one do
    return 1.

procedure none do
    return nothing.

let a be one().
let b be none().
CODE);

        self::assertSame(1, $context->get('a'));
        self::assertNull($context->get('b'));
    }

    public function testProcedureReturnDoesNotLeakAsMainReturnValue(): void
    {
        $context = (new Procer())->run(<<<CODE
procedure one do
    return 1.

let a be one().
CODE);

        self::assertSame(1, $context->get('a'));
        self::assertNull($context->getReturnValue());
    }

    public function testExistsInsideProcedureSeesGlobalScope(): void
    {
        $context = (new Procer())->run(<<<CODE
let x be null.

procedure check do
    return x exists.

let result be check().
CODE);

        self::assertSame(true, $context->get('result'));
    }

    public function testFunctionProviderContextCanReadGlobalFromProcedure(): void
    {
        $provider = new class implements FunctionProviderInterface {
            public function read_x(Context $context): mixed
            {
                return $context->has('x') ? $context->get('x') : 'missing';
            }

            public function supports(string $functionName): bool
            {
                return $functionName === 'read_x';
            }
        };

        $context = (new Procer([$provider]))->run(<<<CODE
let x be 42.

procedure read_from_provider do
    return read_x().

let result be read_from_provider().
CODE);

        self::assertSame(42, $context->get('result'));
    }

    public function testObjectAccessDoesNotCallPublicMethodsThatRequireArguments(): void
    {
        self::expectException(PropertyNotFoundException::class);
        self::expectExceptionMessage("Property or method 'danger' not found");

        $object = new class {
            public function danger(string $value): string
            {
                return $value;
            }
        };

        (new Procer())->runExpression('danger of object', ['object' => $object]);
    }

    public function testObjectAccessAllowsPublicMethodsWithOnlyOptionalArguments(): void
    {
        $object = new class {
            public function label(string $suffix = '-ok'): string
            {
                return 'value' . $suffix;
            }
        };

        $result = (new Procer())->runExpression('label of object', ['object' => $object]);

        self::assertSame('value-ok', $result);
    }
}
