<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\DeserializeObjectProviderInterface;
use Karboosx\Procer\Serializer\LazyDeserializer;
use Karboosx\Procer\Serializer\SerializableObjectInterface;
use PHPUnit\Framework\TestCase;

class ExecutionRecoveryTest extends TestCase
{
    /** @dataProvider interruptTypes */
    public function testInterruptedExpressionKeepsItsPendingOperands(InterruptType $type): void
    {
        $provider = new class($type) implements FunctionProviderInterface {
            private int $calls = 0;
            public function __construct(private InterruptType $type) {}
            public function supports(string $name): bool { return $name === 'ask'; }
            public function ask(Context $context): int|Interrupt
            {
                return ++$this->calls === 1 ? new Interrupt($this->type, 5) : 5;
            }
        };
        $procer = new Procer([$provider]);
        self::assertNull($procer->runExpression('10 + ask()'));
        $context = $procer->resume();
        self::assertTrue($context->isFinished());
        self::assertSame([15], $context->getProcess()->scopes[0]->stack);
    }

    public static function interruptTypes(): array
    {
        return [[InterruptType::BEFORE_EXECUTION], [InterruptType::AFTER_EXECUTION]];
    }

    /** @dataProvider functionKinds */
    public function testCallbackFailurePreservesArgumentsForRetry(bool $objectCall): void
    {
        $failure = new \RuntimeException('temporary failure');
        $provider = new class($failure) implements FunctionProviderInterface {
            public array $arguments = [];
            public function __construct(private \Throwable $failure) {}
            public function supports(string $name): bool { return $name === 'fragile'; }
            public function fragile(Context $context, mixed ...$arguments): int
            {
                $this->arguments[] = $arguments;
                if (count($this->arguments) === 1) {
                    throw $this->failure;
                }
                return $arguments[0] * 10 + $arguments[1];
            }
        };
        $objectProvider = new class($provider) implements ObjectFunctionProviderInterface {
            public function __construct(private FunctionProviderInterface $delegate) {}
            public function supports(object $object, string $name): bool { return $name === 'fragile'; }
            public function fragile(Context $context, object $object, mixed ...$arguments): int
            {
                return $this->delegate->fragile($context, ...$arguments);
            }
        };
        $procer = new Procer([$provider, $objectProvider]);
        $call = $objectCall ? 'fragile(3, 4) on obj' : 'fragile(3, 4)';
        try {
            $procer->run('return 100 + ' . $call . '.', ['obj' => new \stdClass()]);
            self::fail('The callback should throw on its first invocation');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame(134, $procer->resume()->getReturnValue());
        self::assertSame([[3, 4], [3, 4]], $provider->arguments);
    }

    public static function functionKinds(): array { return [[false], [true]]; }

    public function testFailedArithmeticRemainsAFailureOnRepeatedResume(): void
    {
        $procer = new Procer();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $attempt === 0 ? $procer->run('return 100 + (2 / 0).') : $procer->resume();
                self::fail('Retry must not replace failed operands with null');
            } catch (RunnerException $error) {
                self::assertStringContainsString('cannot divide 2 by 0', $error->getMessage());
            }
        }
    }

    public function testThrowingGetterCanBeRetriedWithoutLosingReceiver(): void
    {
        $object = new class {
            public int $calls = 0;
            public function getValue(): int
            {
                if (++$this->calls === 1) { throw new \RuntimeException('try again'); }
                return 7;
            }
        };
        $procer = new Procer();
        try {
            $procer->run('return 10 + value of obj.', ['obj' => $object]);
            self::fail('Expected getter failure');
        } catch (\RuntimeException $error) {
            self::assertSame('try again', $error->getMessage());
        }
        self::assertSame(17, $procer->resume()->getReturnValue());
        self::assertSame(2, $object->calls);
    }

    /** @dataProvider reentrantOperations */
    public function testReentrantExecutionIsRejectedWithoutReplacingActiveProgram(string $operation): void
    {
        $provider = new class($operation) implements FunctionProviderInterface {
            public Procer $procer;
            public ?RunnerException $error = null;
            private bool $entered = false;
            public function __construct(private string $operation) {}
            public function supports(string $name): bool { return $name === 'nested'; }
            public function nested(Context $context): int
            {
                if ($this->entered) { return 999; }
                $this->entered = true;
                try {
                    match ($this->operation) {
                        'run' => $this->procer->run('return 999.'),
                        'runExpression' => $this->procer->runExpression('999'),
                        'resume' => $this->procer->resume(),
                    };
                } catch (RunnerException $error) {
                    $this->error = $error;
                }
                return 2;
            }
        };
        $procer = $provider->procer = new Procer([$provider]);
        $procer->setMaxCycles(30);
        self::assertSame(12, $procer->run('return 10 + nested().')->getReturnValue());
        self::assertInstanceOf(RunnerException::class, $provider->error);
        self::assertStringContainsString('already running', $provider->error->getMessage());
    }

    public static function reentrantOperations(): array
    {
        return [['run'], ['runExpression'], ['resume']];
    }

    /** @dataProvider lazyOperandCases */
    public function testLazySnapshotResolvesObjectsAlreadyOnOperandStack(string $source, bool $failOnce): void
    {
        $object = new RecoveryRecord();
        $provider = new class implements FunctionProviderInterface {
            public function supports(string $name): bool { return in_array($name, ['ask', 'combine'], true); }
            public function ask(Context $context): Interrupt { return new Interrupt(InterruptType::AFTER_EXECUTION, 2); }
            public function combine(Context $context, int $value, RecoveryRecord $record): int { return $value + $record->value; }
        };
        $objects = new class($object, $failOnce) implements DeserializeObjectProviderInterface {
            public int $loads = 0;
            public function __construct(private RecoveryRecord $record, private bool $failOnce) {}
            public function supports(string $id): bool { return $id === 'record'; }
            public function deserialize(string $id): mixed
            {
                if (++$this->loads === 1 && $this->failOnce) { throw new \RuntimeException('object unavailable'); }
                return $this->record;
            }
        };
        $context = (new Procer([$provider]))->run($source, ['obj' => $object]);
        $process = (new LazyDeserializer($objects))->deserialize($context->serialize());
        self::assertSame(0, $objects->loads);
        $procer = new Procer([$provider]);
        if ($failOnce) {
            try {
                $procer->resume($process);
                self::fail('Expected the lazy loader to fail once');
            } catch (\RuntimeException $error) {
                self::assertSame('object unavailable', $error->getMessage());
                self::assertCount(1, $process->scopes, 'Failed argument resolution must not enter a procedure');
            }
        }
        $context = $procer->resume($process);
        self::assertSame(42, $context->getReturnValue());
        self::assertSame($failOnce ? 2 : 1, $objects->loads);
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }

    public static function lazyOperandCases(): iterable
    {
        foreach ([
            'return combine(ask(), obj).',
            "procedure read(n, item) do\n    return n + value of item.\nreturn read(ask(), obj).",
        ] as $source) {
            foreach ([false, true] as $failOnce) { yield [$source, $failOnce]; }
        }
    }

    public function testCompletedLazySnapshotExposesTheRealReturnObject(): void
    {
        $record = new RecoveryRecord();
        $objects = new class($record) implements DeserializeObjectProviderInterface {
            public int $loads = 0;
            public function __construct(private RecoveryRecord $record) {}
            public function supports(string $id): bool { return $id === 'record'; }
            public function deserialize(string $id): mixed { $this->loads++; return $this->record; }
        };
        $context = (new Procer())->run('return obj.', ['obj' => $record]);
        $process = (new LazyDeserializer($objects))->deserialize($context->serialize());
        $context = (new Procer())->resume($process);
        self::assertSame(0, $objects->loads);
        self::assertSame($record, $context->getReturnValue());
        self::assertSame($record, $context->getReturnValue());
        self::assertSame(1, $objects->loads);
    }

    public function testPublicStaticPropertyIsReadWithoutPhpWarnings(): void
    {
        $object = new class { public static int $value = 42; };
        self::assertSame(42, (new Procer())->runExpression('value of obj', ['obj' => $object]));
    }

    public function testIndependentInterpreterCanRunInsideCallback(): void
    {
        $provider = new class implements FunctionProviderInterface {
            public function supports(string $name): bool { return $name === 'nested'; }
            public function nested(Context $context): int { return (new Procer())->runExpression('3 * 4'); }
        };
        self::assertSame(112, (new Procer([$provider]))->run('return 100 + nested().')->getReturnValue());
    }

    public function testInternalFunctionFailurePreservesItsOperands(): void
    {
        $procer = new Procer();
        $message = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $attempt === 0 ? $procer->run("from 1 to 3 by 0 do\n    nothing.") : $procer->resume();
                self::fail('An invalid range must keep failing until its cause is corrected');
            } catch (RunnerException $error) {
                $message ??= $error->getMessage();
                self::assertSame($message, $error->getMessage());
                self::assertStringContainsString('Range step must not be zero', $message);
            }
        }
    }

    public function testMissingVariableCanBeSuppliedWithoutRepeatingEarlierCalculation(): void
    {
        $procer = new Procer();
        try {
            $procer->run('return 10 + missing * 2.');
            self::fail('Expected a missing-variable failure');
        } catch (\Karboosx\Procer\Exception\VariableNotFoundException $error) {
            self::assertStringContainsString('missing', $error->getMessage());
        }
        self::assertSame(20, $procer->resume(null, ['missing' => 5])->getReturnValue());
    }
}

class RecoveryRecord implements SerializableObjectInterface
{
    public int $value = 40;
    public function getSerializeId(): string { return 'record'; }
}
