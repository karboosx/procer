<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\OperationFailure;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowReviewTest extends TestCase
{
    public static function loopNames(): iterable
    {
        foreach (['on', 'as', 'step', 'next', 'failure', 'do', 'done', 'or', 'if', 'return', 'from', 'for', 'in', 'while', 'until', 'true', 'null', 'item', 'item_name', 'item2'] as $name) {
            foreach ([false, true] as $done) {
                foreach ([false, true] as $range) {
                    foreach ([false, true] as $handler) { yield "$name/$done/$range/$handler" => [$name, $done, $range, $handler]; }
                }
            }
        }
    }

    /** @dataProvider loopNames */
    public function testNextAcceptsEveryLegalLoopName(string $name, bool $done, bool $range, bool $handler): void
    {
        $body = 'let count be count + 1.' . "\n" . ($handler
            ? WorkflowLanguageTest::step('fail("expected").', "next $name.", $done)
            : "next $name.") . "\nfail(\"unreachable\").";
        $source = 'let count be 0.' . "\n" . WorkflowLanguageTest::block(
            $range ? "from 3 to 1 by -1 as $name" : "for each $name in items", $body, $done
        ) . "\nreturn count.";
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $p->setMaxCycles(1000);
        $c = $p->run($source, ['items' => [10 => 4, 40 => 5, 'sparse' => 6]]);
        self::assertSame(3, $c->getReturnValue(), $source);
        self::assertSame([], $c->getProcess()->scopes[0]->steps);
        self::assertSame([], $c->getProcess()->scopes[0]->stack);
    }

    public function testNextRemainsAnOrdinaryProviderFunctionAndMethod(): void
    {
        $c = (new Procer([new WorkflowProbe()]))->run(
            'next(). next. next on obj. on obj next. next() on obj. return next on obj.',
            ['obj' => new \stdClass()]
        );
        self::assertSame(2, $c->getReturnValue());
    }

    public static function failurePositions(): iterable
    {
        foreach ([false, true] as $done) {
            $block = fn($header, $body = 'mark("unreachable", 0).') => WorkflowLanguageTest::block($header, $body, $done);
            $cases = [
                'assignment' => 'let result be fail("expected").',
                'first record field' => 'let result be fail("expected") as "a", mark("unreachable", 0) as "b".',
                'nested record field' => 'let result be 1 as "a", (fail("expected") as "inner") as "b".',
                'return' => 'return 2 + fail("expected").',
                'function argument' => 'let result be mark("unreachable", fail("expected")).',
                'object argument' => 'let result be mark("unreachable", fail("expected")) on obj.',
                'object call' => 'fail("expected") on obj.',
                'if condition' => $block('if fail("expected")'),
                'or condition' => "if false do\n    nothing.\nor fail(\"expected\") do\n    mark(\"unreachable\", 0)." . ($done ? "\ndone" : ''),
                'while condition' => $block('while fail("expected")'),
                'until condition' => $block('until fail("expected")'),
                'stopping condition' => $block('while stopping fail("expected")'),
                'range start' => $block('from fail("expected") to 3 as i'),
                'range end' => $block('from 1 to fail("expected") as i'),
                'range step' => $block('from 1 to 3 by fail("expected") as i'),
                'foreach source' => $block('for each i in fail("expected")'),
            ];
            foreach ($cases as $name => $body) {
                foreach (['direct', 'nested', 'procedure'] as $placement) { yield "$name/$placement/$done" => [$body, $done, $placement]; }
            }
        }
    }

    /** @dataProvider failurePositions */
    public function testRecoverableFailureInEveryEvaluationPosition(string $body, bool $done, string $placement): void
    {
        $prefix = '';
        if ($placement === 'nested') { $body = WorkflowLanguageTest::step($body, null, $done, 'inner'); }
        if ($placement === 'procedure') {
            $prefix = WorkflowLanguageTest::block('procedure execute', $body, $done) . "\n";
            $body = 'let result be 7 as "partial", execute() as "value".';
        }
        $source = $prefix . 'let result be "unchanged".' . "\n" . WorkflowLanguageTest::step(
            $body . "\nmark(\"unreachable\", 0).", 'let captured be problem. stop.', $done, 'outer'
        ) . "\nreturn result as \"result\", captured as \"failure\".";
        $probe = new WorkflowProbe(); $p = new Procer([$probe]); $p->useDoneKeyword($done); $p->setMaxCycles(1000);
        $c = $p->run($source, ['obj' => new \stdClass()]);
        self::assertFalse($c->isFinished());
        self::assertCount(1, $c->getProcess()->scopes);
        self::assertSame([], $c->getProcess()->scopes[0]->stack);
        $restored = (new Procer([$probe]))->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertSame('unchanged', $restored->getReturnValue()->result);
        self::assertSame('expected', $restored->getReturnValue()->failure->message);
        self::assertSame('fail', $restored->getReturnValue()->failure->operation);
        self::assertSame($placement === 'nested' ? 'inner' : 'outer', $restored->getReturnValue()->failure->step);
        self::assertSame(['expected'], $probe->trace);
        self::assertFalse($restored->has('problem'));
        self::assertSame([], $restored->getProcess()->scopes[0]->steps);
    }

    public static function skippedFailures(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (['false and fail("unreachable")', 'true or fail("unreachable")'] as $condition) {
                yield [$condition, $condition[0] === 't', $done];
            }
        }
    }

    /** @dataProvider skippedFailures */
    public function testShortCircuitedFailureDoesNotEnterHandler(string $condition, bool $expected, bool $done): void
    {
        $probe = new WorkflowProbe(); $p = new Procer([$probe]); $p->useDoneKeyword($done);
        $c = $p->run(WorkflowLanguageTest::step("let result be $condition as \"value\".", 'fail("wrong handler").', $done) . "\nreturn result.");
        self::assertSame($expected, $c->getReturnValue()->value); self::assertSame([], $probe->trace);
    }

    public static function emptyLoops(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (['for each i in items', 'from 2 to 1 as i', 'from 1 to 2 by -1 as i'] as $header) { yield [$header, $done]; }
        }
    }

    /** @dataProvider emptyLoops */
    public function testZeroIterationLoopsDoNotEnterStepsOrHandlers(string $header, bool $done): void
    {
        $probe = new WorkflowProbe(); $p = new Procer([$probe]); $p->useDoneKeyword($done);
        $body = WorkflowLanguageTest::step('fail("unreachable").', 'next i.', $done);
        $c = $p->run(WorkflowLanguageTest::block($header, $body, $done) . "\nreturn 9.", ['items' => []]);
        self::assertSame(9, $c->getReturnValue()); self::assertSame([], $probe->trace);
        self::assertSame([], $c->getProcess()->scopes[0]->steps);
    }

    public static function invalidRuntime(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (['let x be 1 / 0.', 'let x be obj - 1.', 'let x be field of obj.', 'on obj missing.',
                WorkflowLanguageTest::block('from 1 to 3 by 0 as i', 'next i.', $done),
                WorkflowLanguageTest::block('for each i in 7', 'next i.', $done)] as $body) { yield [$body, $done]; }
        }
    }

    /** @dataProvider invalidRuntime */
    public function testProgrammingErrorsBypassHandlersAndKeepTheInterpreterReusable(string $body, bool $done): void
    {
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done);
        try { $p->run(WorkflowLanguageTest::step($body, 'return "incorrect".', $done), ['obj' => new \stdClass()]); self::fail('Expected runtime error'); }
        catch (RunnerException $error) { self::assertNotSame('', $error->getMessage()); }
        self::assertSame(4, $p->run('return 4.')->getReturnValue());
    }

    /** @dataProvider bindingNames */
    public function testFailureAliasesWithContextualKeywordNames(string $name, bool $done): void
    {
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done);
        $source = "let $name be \"previous\".\n" . WorkflowLanguageTest::step('fail("expected").', "let captured be message of $name. stop.", $done, '', $name)
            . "\nreturn captured.";
        $c = $p->run($source);
        $c = (new Procer())->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertSame('expected', $c->getReturnValue()); self::assertSame('previous', $c->get($name));
    }
    public static function bindingNames(): iterable
    {
        foreach (['on', 'as', 'step', 'next', 'failure', 'do', 'done', 'or', 'return'] as $name) {
            foreach ([false, true] as $done) { yield [$name, $done]; }
        }
    }

    public function testRecoverableSubclassIsCaughtButReturnedFailureObjectIsOnlyAValue(): void
    {
        $failure = new class('expected', 31, new \RuntimeException('private cause')) extends OperationFailure {};
        $provider = new class($failure) implements FunctionProviderInterface {
            public function __construct(private OperationFailure $failure) {}
            public function supports(string $name): bool { return in_array($name, ['give', 'raise'], true); }
            public function give(Context $c): object { return $this->failure; }
            public function raise(Context $c): never { throw $this->failure; }
        };
        $p = new Procer([$provider]);
        $source = WorkflowLanguageTest::step('let value be give().', 'return "wrong".', false) . "\n" .
            WorkflowLanguageTest::step('raise().', 'let captured be problem.', false);
        $c = $p->run($source);
        self::assertSame($failure, $c->get('value'));
        self::assertSame(31, $c->get('captured')->code);
        self::assertSame(['message', 'code', 'step', 'operation', 'line', 'column'], array_keys(get_object_vars($c->get('captured'))));
    }
}
