<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\Serializer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowRecoveryTest extends TestCase
{
    private function interpreter(WorkflowProbe $probe, bool $done): Procer
    {
        $p = new Procer([$probe]); $p->useDoneKeyword($done); $p->setMaxCycles(10000); return $p;
    }
    public static function programs(bool $done): array
    {
        $step = fn($body, $handler, $label = 'work') => WorkflowLanguageTest::step($body, $handler, $done, $label);
        $block = fn($header, $body) => WorkflowLanguageTest::block($header, $body, $done);
        return [
            'record' => ['return mark("one", 1) as "one", (mark("two", 2) as "two") as "nested".', (object)['one' => 1, 'nested' => (object)['two' => 2]]],
            'handled partial record' => [$step('let result be mark("one", 1) as "one", fail("expected") as "two".',
                'let result be message of problem as "message", mark("handler", 2) as "value".') . "\nreturn result.", (object)['message' => 'expected', 'value' => 2]],
            'nested handlers' => [$step($step('fail("inner").', 'mark("inner handler", 0). fail("outer").', 'inner'),
                'return mark("outer handler", message of problem).', 'outer'), 'outer'],
            'recursive call unwind' => [$block('procedure recurse(n)', $block('if n > 0', 'return 10 + recurse(n - 1).') . "\nfail(\"deep\").") . "\n" .
                $step('let x be mark("left", 2) as "left", recurse(3) as "right".', 'return mark("handler", message of problem).'), 'deep'],
            'object calls' => [$step('let a be mark("object", 2) on obj. fail("object failed") on obj.',
                'return mark("handled", a) on obj.'), 2],
            'next through handlers' => ['let total be 0.' . "\n" . $block('for each item in items',
                $step('mark("before", item). fail("skip").', 'let total be total + mark("counter", item). next item.') . "\nfail(\"never\").") . "\nreturn total.", 6],
            'preserve outer step' => [$step('let sum be 0.' . "\n" . $block('from 1 to 3 as i',
                $step('let sum be sum + mark("value", i). next i.', 'fail("never").')) . "\nreturn sum.", 'return -100.'), 6],
            'handler parameter binding' => ['let problem be "global".' . "\n" . $block('procedure f(problem)',
                $step('fail("local").', 'mark("inside", message of problem).') . "\nreturn mark(\"after\", problem).") . "\nreturn f(\"parameter\").", 'parameter'],
            'return cleans handler' => [$block('procedure f', $step('fail("expected").', 'return mark("return", 8).')) . "\nreturn 1 + f().", 9],
            'nested record arguments' => ['return mark("argument", (mark("a", 2) as "a", mark("b", 3) as "b")).', (object)['a' => 2, 'b' => 3]],
            'stopping loop escape' => ['let sum be 0.' . "\n" . $block('from 1 to 3 as i', $block('while stopping true',
                $step('fail("skip").', 'let sum be sum + i. next i.'))) . "\nreturn sum.", 6],
            'repeated named loop uses nearest' => ['let count be 0.' . "\n" . $block('for each item in items', $block('for each item in items',
                'let count be count + 1. next item. fail("never").')) . "\nreturn count.", 9],
        ];
    }

    public static function interruptions(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (self::programs($done) as $name => [$source, $expected]) {
                foreach (InterruptType::cases() as $type) {
                    foreach ([false, true] as $restore) {
                        foreach (["\n", "\r\n", "\r"] as $eol) {
                            yield "$name / $done / {$type->name} / $restore / " . bin2hex($eol) => [str_replace("\n", $eol, $source), $expected, $done, $type, $restore];
                        }
                    }
                }
            }
        }
    }

    /** @dataProvider interruptions */
    public function testPauseAndRestorePreserveWorkflow(string $source, mixed $expected, bool $done, InterruptType $type, bool $restore): void
    {
        $reference = new WorkflowProbe();
        $baseline = $this->interpreter($reference, $done)->run($source, ['items' => [1, 2, 3], 'obj' => new \stdClass()]);
        self::assertEquals($expected, $baseline->getReturnValue(), $source);
        $probe = new WorkflowProbe($type); $p = $this->interpreter($probe, $done);
        $context = $p->run($source, ['items' => [1, 2, 3], 'obj' => new \stdClass()]);
        for ($resumes = 0; !$context->isFinished(); $resumes++) {
            self::assertLessThan(100, $resumes);
            if ($restore) {
                $process = (new Deserializer())->deserialize($context->serialize());
                $p = $this->interpreter($probe, $done); $context = $p->resume($process);
            } else { $context = $p->resume(); }
        }
        self::assertEquals($expected, $context->getReturnValue());
        $expectedTrace = $type === InterruptType::BEFORE_EXECUTION && str_contains($source, 'return mark("argument",')
            ? ['a', 'b', 'a', 'b', 'argument'] : $reference->trace;
        self::assertSame($expectedTrace, $probe->trace, 'AFTER preserves results; BEFORE explicitly reevaluates call arguments');
        self::assertEquals($baseline->getProcess()->scopes, $context->getProcess()->scopes);
        self::assertEquals($expected, $p->resume()->getReturnValue());
    }

    public static function boundaries(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (self::programs($done) as $name => [$source, $expected]) { yield "$name / $done" => [$source, $expected, $done]; }
        }
    }

    /** @dataProvider boundaries */
    public function testEveryInstructionBoundarySurvivesFreshInterpreter(string $source, mixed $expected, bool $done): void
    {
        $reference = new WorkflowProbe(); $vars = ['items' => [1, 2, 3], 'obj' => new \stdClass()];
        $baseline = $this->interpreter($reference, $done)->run($source, $vars);
        $process = new Process(); $process->ic = (new ICParser())->parse((new Parser(new Tokenizer(), $done))->parse($source));
        $process->scopes[0]->variables = $vars; $probe = new WorkflowProbe();
        for ($boundary = 0; $boundary < 1000; $boundary++) {
            $process = (new Deserializer())->deserialize((new Serializer())->serialize($process));
            $p = $this->interpreter($probe, $done); $p->setMaxCycles($process->cycles + 1);
            try {
                $context = $p->resume($process);
                if (!$context->isFinished()) { continue; }
                self::assertEquals($expected, $context->getReturnValue());
                self::assertSame($reference->trace, $probe->trace);
                self::assertSame($baseline->getProcess()->cycles, $process->cycles);
                self::assertEquals($baseline->getProcess()->scopes, $process->scopes);
                return;
            } catch (MaxCyclesException) { self::assertSame($boundary + 1, $process->cycles); }
        }
        self::fail('Boundary budget exhausted');
    }

    /** @dataProvider waits */
    public function testStopAndSignalsInsideBodyOrHandlerPreserveBinding(bool $handler, bool $signal, bool $done): void
    {
        $wait = $signal ? 'wait for signal ready.' : 'stop.';
        $source = 'let problem be "original".' . "\n" . WorkflowLanguageTest::step(
            $handler ? 'fail("failed").' : $wait,
            $handler ? $wait . ' let captured be message of problem.' : 'nothing.', $done
        ) . "\nreturn problem.";
        $p = $this->interpreter(new WorkflowProbe(), $done); $c = $p->run($source);
        self::assertFalse($c->isFinished());
        $process = (new Deserializer())->deserialize($c->serialize());
        if ($handler) { self::assertSame('failed', $process->scopes[0]->variables['problem']->message); }
        $c = $this->interpreter(new WorkflowProbe(), $done)->resume($process, [], ['ready']);
        self::assertSame('original', $c->getReturnValue());
        if ($handler) { self::assertSame('failed', $c->get('captured')); }
    }
    public static function waits(): iterable
    {
        foreach ([false, true] as $a) { foreach ([false, true] as $b) { foreach ([false, true] as $c) { yield [$a, $b, $c]; } } }
    }

    /** @dataProvider corruptFrames */
    public function testCorruptStepSnapshotsAreRejected(string $change, bool $handling): void
    {
        $source = WorkflowLanguageTest::step($handling ? 'fail("bad").' : 'stop.', $handling ? 'stop.' : 'nothing.', false);
        $p = $this->interpreter(new WorkflowProbe(), false); $raw = json_decode($p->run($source)->serialize(), true);
        switch ($change) {
            case 'missing': unset($raw['s'][0]['h']); break;
            case 'lost frame': $raw['s'][0]['h'] = []; break;
            case 'not list': $raw['s'][0]['h'] = ['bad' => []]; break;
            case 'missing frame field': unset($raw['s'][0]['h'][0]['handling']); break;
            case 'wrong instruction': $raw['s'][0]['h'][0]['instruction'] = 'i:1'; break;
            case 'negative instruction': $raw['s'][0]['h'][0]['instruction'] = 'i:-1'; break;
            case 'instruction past end': $raw['s'][0]['h'][0]['instruction'] = 'i:999999'; break;
            case 'stack past end': $raw['s'][0]['h'][0]['stack'] = 'i:100'; break;
            case 'negative stack': $raw['s'][0]['h'][0]['stack'] = 'i:-1'; break;
            case 'wrong bool': $raw['s'][0]['h'][0]['handling'] = 'i:0'; break;
            case 'wrong handling': $raw['s'][0]['h'][0]['handling'] = $handling ? 'b:0' : 'b:1'; break;
            case 'bad binding value': $raw['s'][0]['h'][0]['bindingValue'] = 'i:4'; break;
            case 'duplicate frame': $raw['s'][0]['h'][] = $raw['s'][0]['h'][0]; break;
            case 'v1': $raw['v'] = 1; break;
            case 'future version': $raw['v'] = 99; break;
            case 'bad handler pointer': $raw['ic']['i'][0]['a'][1] = 'i:999999'; break;
            case 'bad end pointer': $raw['ic']['i'][0]['a'][3] = 'i:0'; break;
            case 'bad pointer': $raw['i'] = 'i:' . count($raw['ic']['i']); break;
        }
        $this->expectException(DeserializationException::class);
        (new Deserializer())->deserialize(json_encode($raw));
    }
    public static function corruptFrames(): iterable
    {
        foreach (['missing', 'lost frame', 'not list', 'missing frame field', 'wrong instruction', 'negative instruction', 'instruction past end',
            'stack past end', 'negative stack', 'wrong bool', 'wrong handling', 'bad binding value', 'duplicate frame', 'v1', 'future version',
            'bad handler pointer', 'bad end pointer', 'bad pointer'] as $change) {
            foreach ([false, true] as $handling) { yield "$change / $handling" => [$change, $handling]; }
        }
    }
}
