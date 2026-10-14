<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptReason;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\Serializer;
use PHPUnit\Framework\TestCase;

class ExecutionInteractionTest extends TestCase
{
    /** @dataProvider interruptionCases */
    public function testInterruptionsInNestedControlFlow(
        string $source, mixed $expected, bool $done, InterruptType $type, bool $restore
    ): void {
        $reference = new InteractionProbe();
        $baseline = $this->interpreter($reference, $done)->run($source, ['obj' => new \stdClass()]);
        self::assertSame($expected, $baseline->getReturnValue(), $source);

        $probe = new InteractionProbe($type);
        $procer = $this->interpreter($probe, $done);
        $context = $procer->run($source, ['obj' => new \stdClass()]);
        for ($resumes = 0; !$context->isFinished(); $resumes++) {
            self::assertLessThan(100, $resumes, 'Interruptions must eventually finish: ' . $source);
            self::assertSame(InterruptReason::FUNCTION_REQUEST, $context->getInterruptReason());
            self::assertIsString($context->getInterruptData());
            if ($restore) {
                $process = (new Deserializer())->deserialize($context->serialize());
                $procer = $this->interpreter($probe, $done);
                $context = $procer->resume($process);
            } else {
                $context = $procer->resume();
            }
        }
        self::assertGreaterThan(0, $resumes);
        self::assertSame($expected, $context->getReturnValue(), $source);
        self::assertCount(1, $context->getProcess()->scopes);
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
        self::assertSame($expected, $procer->resume()->getReturnValue());
        if ($type === InterruptType::AFTER_EXECUTION) {
            self::assertSame($reference->calls, $probe->calls, 'Completed calls must not be repeated');
        }
    }

    public static function interruptionCases(): iterable
    {
        foreach (self::programs() as $name => [$indent, $done, $expected]) {
            foreach ([false => $indent, true => $done] as $mode => $source) {
                foreach (InterruptType::cases() as $type) {
                    foreach ([false, true] as $restore) {
                        yield "$name / mode=$mode / {$type->name} / restore=" . (int)$restore => [
                            $source, $expected, (bool)$mode, $type, $restore,
                        ];
                    }
                }
            }
        }
    }

    /** @dataProvider instructionBoundaryCases */
    public function testSnapshotAndFreshInterpreterAtEveryExecutedInstructionBoundary(
        string $source, mixed $expected, bool $done
    ): void {
        $reference = new InteractionProbe();
        $variables = ['obj' => (object)['value' => 2], 'items' => [2 => 1, 7 => 3]];
        $baseline = $this->interpreter($reference, $done)->run($source, $variables);
        self::assertSame($expected, $baseline->getReturnValue(), $source);

        $process = new Process();
        $process->ic = (new ICParser())->parse((new Parser(new Tokenizer(), $done))->parse($source));
        $process->scopes[0]->variables = $variables;
        $probe = new InteractionProbe();
        $serializer = new Serializer();
        $deserializer = new Deserializer();
        for ($boundary = 0; $boundary < 1000; $boundary++) {
            $process = $deserializer->deserialize($serializer->serialize($process));
            $procer = $this->interpreter($probe, $done);
            $procer->setMaxCycles($process->cycles + 1);
            try {
                $context = $procer->resume($process);
                if ($context->isFinished()) {
                    self::assertSame($expected, $context->getReturnValue(), $source);
                    self::assertEquals($baseline->getProcess()->scopes, $process->scopes);
                    self::assertSame($baseline->getProcess()->cycles, $process->cycles);
                    self::assertSame($reference->calls, $probe->calls);
                    return;
                }
            } catch (MaxCyclesException $error) {
                self::assertSame($boundary + 1, $process->cycles, 'Exactly one instruction must run');
            }
        }
        self::fail('Execution did not finish within the boundary-test budget: ' . $source);
    }

    public static function instructionBoundaryCases(): iterable
    {
        foreach (self::programs() as $name => [$indent, $done, $expected]) {
            yield "$name / indentation" => [$indent, $expected, false];
            yield "$name / done" => [$done, $expected, true];
        }
        yield 'mutual recursion and parameter shadowing' => [
            "let n be 99.\nprocedure even(n) do\n    if n is 0 do\n        return true.\n    return odd(n - 1).\nprocedure odd(n) do\n    if n is 0 do\n        return false.\n    return even(n - 1).\nreturn even(8) and n is 99.",
            true, false,
        ];
        yield 'sparse arrays, nested ranges, while, until, properties' => [
            "let total be 0.\nfor each item in items do\n    from 2 to 1 by -1 as i do\n        let total be total + item * i.\nwhile total < 20 do\n    let total be total + 1.\nuntil total >= 23 do\n    let total be total + 1.\nreturn total + value of obj.",
            25, false,
        ];
    }

    public function testRepeatedSignalResumesDoNotAccumulateSignalsOrRepeatCompletedWork(): void
    {
        $probe = new InteractionProbe();
        $procer = $this->interpreter($probe, false);
        $context = $procer->run('checkpoint("before", 1). wait for all signals red, green. return checkpoint("after", 42).');
        $pointer = $context->getProcess()->currentInstructionIndex;
        foreach ([[], ['red'], ['green'], ['unrelated'], ['red']] as $signals) {
            $process = (new Deserializer())->deserialize($context->serialize());
            $context = $this->interpreter($probe, false)->resume($process, [], $signals);
            self::assertFalse($context->isFinished());
            self::assertSame($pointer, $process->currentInstructionIndex);
            self::assertSame(['red', 'green'], $context->getWaitForSignalValue());
            self::assertSame([['before', 1]], $probe->calls);
        }
        $process = (new Deserializer())->deserialize($context->serialize());
        $context = $this->interpreter($probe, false)->resume($process, [], ['green', 'red']);
        self::assertSame(42, $context->getReturnValue());
        self::assertNull($context->getWaitForSignalValue());
        self::assertSame([['before', 1], ['after', 42]], $probe->calls);
    }

    /** @dataProvider stoppingLoopCases */
    public function testStoppingLoopsResumeAtTheCorrectIteration(string $loop, bool $done, int $initial): void
    {
        $condition = $loop === 'while' ? 'value < 3' : 'value >= 3';
        $source = "let value be $initial.\n$loop stopping $condition do\n    let value be value + 1.\n";
        $source .= ($done ? 'done ' : '') . 'return value.';
        $procer = new Procer();
        $procer->useDoneKeyword($done);
        $procer->setMaxCycles(200);
        $context = $procer->run($source);
        for ($value = $initial + 1; $value <= 3; $value++) {
            self::assertFalse($context->isFinished());
            self::assertSame($value, $context->get('value'));
            self::assertSame(InterruptReason::WHILE_STOPPING, $context->getInterruptReason());
            self::assertSame([], $context->getProcess()->scopes[0]->stack);
            $process = (new Deserializer())->deserialize($context->serialize());
            $procer = new Procer();
            $procer->setMaxCycles(200);
            $context = $procer->resume($process);
        }
        self::assertTrue($context->isFinished());
        self::assertSame(3, $context->getReturnValue());
        self::assertSame(3, $procer->resume()->getReturnValue());
    }

    public static function stoppingLoopCases(): iterable
    {
        foreach (['while', 'until'] as $loop) {
            foreach ([false, true] as $done) {
                foreach ([0, 3] as $initial) { yield [$loop, $done, $initial]; }
            }
        }
    }

    private function interpreter(InteractionProbe $probe, bool $done): Procer
    {
        $objectProvider = new class($probe) implements ObjectFunctionProviderInterface {
            public function __construct(private InteractionProbe $probe) {}
            public function supports(object $object, string $name): bool { return $name === 'checkpoint'; }
            public function checkpoint(Context $context, object $object, string $tag, mixed $value): mixed
            {
                return $this->probe->checkpoint($context, $tag, $value);
            }
        };
        $procer = new Procer([$probe, $objectProvider]);
        $procer->useDoneKeyword($done);
        $procer->setMaxCycles(20000);
        return $procer;
    }

    private static function programs(): array
    {
        $arithmetic = 'return 10 + checkpoint("left", 2) * checkpoint("right", 3).';
        $nested = 'return join_values(checkpoint("outer", checkpoint("inner", 2) + 1), checkpoint("right", 4)).';
        $boolean = 'return (false and checkpoint("skip", true)) or checkpoint("needed", true).';
        $object = 'return 10 + checkpoint("object", 2) on obj.';
        return [
            'arithmetic' => [$arithmetic, $arithmetic, 16],
            'nested arguments' => [$nested, $nested, 34],
            'short circuit' => [$boolean, $boolean, true],
            'object function' => [$object, $object, 12],
            'branch chain' => [
                "if checkpoint(\"condition\", false) do\n    return 0.\nor checkpoint(\"alternate\", true) do\n    return checkpoint(\"result\", 9).\nif not do\n    return -1.",
                'if checkpoint("condition", false) do return 0. or checkpoint("alternate", true) do return checkpoint("result", 9). if not do return -1. done', 9,
            ],
            'loop bounds and body' => [
                "let sum be 0.\nfrom 1 to checkpoint(\"end\", 3) by checkpoint(\"step\", 1) as i do\n    let sum be sum + checkpoint(\"item\", i).\nreturn sum.",
                'let sum be 0. from 1 to checkpoint("end", 3) by checkpoint("step", 1) as i do let sum be sum + checkpoint("item", i). done return sum.', 6,
            ],
            'recursive procedure' => [
                "procedure sum(n) do\n    if n is 0 do\n        return 0.\n    return checkpoint(\"item\", n) + sum(n - 1).\nreturn sum(4).",
                'procedure sum(n) do if n is 0 do return 0. done return checkpoint("item", n) + sum(n - 1). done return sum(4).', 10,
            ],
            'implicit procedure return' => [
                "procedure maybe(flag) do\n    if flag do\n        return checkpoint(\"value\", 4).\nlet a be maybe(false).\nreturn a is null and maybe(true) is 4.",
                'procedure maybe(flag) do if flag do return checkpoint("value", 4). done done let a be maybe(false). return a is null and maybe(true) is 4.', true,
            ],
        ];
    }
}

class InteractionProbe implements FunctionProviderInterface
{
    public array $calls = [];
    private array $pending = [];
    public function __construct(private ?InterruptType $type = null) {}
    public function supports(string $name): bool { return in_array($name, ['checkpoint', 'join_values'], true); }
    public function join_values(Context $context, int $first, int $second): int { return $first * 10 + $second; }
    public function checkpoint(Context $context, string $tag, mixed $value): mixed
    {
        $this->calls[] = [$tag, $value];
        if ($this->type === InterruptType::AFTER_EXECUTION) {
            return new Interrupt($this->type, $value, $tag);
        }
        if ($this->type === InterruptType::BEFORE_EXECUTION) {
            if (!isset($this->pending[$tag])) {
                $this->pending[$tag] = true;
                return new Interrupt($this->type, null, $tag);
            }
            unset($this->pending[$tag]);
        }
        return $value;
    }
}
