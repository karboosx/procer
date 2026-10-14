<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\OperationFailure;
use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowLanguageTest extends TestCase
{
    public static function block(string $header, string $body, bool $done): string
    {
        return $header . " do\n" . self::indent($body) . ($done ? "\ndone" : '');
    }
    public static function indent(string $body): string { return '    ' . str_replace("\n", "\n    ", $body); }
    public static function step(string $body, ?string $handler, bool $done, string $label = 'work', string $binding = 'problem'): string
    {
        return 'step "' . $label . '" do' . "\n" . self::indent($body) .
            ($handler === null ? '' : "\non failure as $binding do\n" . self::indent($handler)) . ($done ? "\ndone" : '');
    }
    public static function modes(): array { return [[false], [true]]; }

    /** @dataProvider modes */
    public function testFirstFailureSkipsRemainingBodyAndRestoresBinding(bool $done): void
    {
        $source = "let problem be null. let result be \"old\".\n" . self::step(
            'let result be mark("first", 1) as "a", fail("bad") as "b", mark("never", 2) as "c".',
            'let diagnostic be problem. mark("handler", 0).', $done
        ) . "\nreturn result.";
        $probe = new WorkflowProbe(); $procer = new Procer([$probe]); $procer->useDoneKeyword($done);
        $context = $procer->run($source);
        self::assertSame('old', $context->getReturnValue());
        self::assertSame(['first', 'bad', 'handler'], $probe->trace);
        self::assertNull($context->get('problem')); self::assertTrue($context->has('problem'));
        self::assertEquals((object)['message' => 'bad', 'code' => 17, 'step' => 'work', 'operation' => 'fail', 'line' => 3, 'column' => 43], $context->get('diagnostic'));
        self::assertSame([], $context->getProcess()->scopes[0]->steps);
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }

    /** @dataProvider modes */
    public function testSuccessSkipsHandlerAndOrdinaryVariablesRemainInScope(bool $done): void
    {
        $source = self::step('let value be 4.', 'fail("must not run").', $done) . "\nreturn value.";
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done);
        $c = $p->run($source);
        self::assertSame(4, $c->getReturnValue()); self::assertFalse($c->has('problem'));
    }

    /** @dataProvider modes */
    public function testNearestHandlerAndFailureInsideHandlerPropagateOnce(bool $done): void
    {
        $inner = self::step('fail("first").', 'mark("inner handler", 0). fail("second"). mark("never", 0).', $done, 'inner');
        $source = self::step($inner . "\nmark(\"never outer\", 0).", 'let captured be problem. mark("outer handler", 0).', $done, 'outer');
        $probe = new WorkflowProbe(); $p = new Procer([$probe]); $p->useDoneKeyword($done);
        $c = $p->run($source);
        self::assertSame(['first', 'inner handler', 'second', 'outer handler'], $probe->trace);
        self::assertSame('second', $c->get('captured')->message);
        self::assertSame('inner', $c->get('captured')->step);
        self::assertFalse($c->has('problem')); self::assertSame([], $c->getProcess()->scopes[0]->steps);
    }

    /** @dataProvider modes */
    public function testFailureUnwindsRecursiveProceduresAndPartialExpressions(bool $done): void
    {
        $body = self::block('if n > 0', 'return 10 + recurse(n - 1).', $done) . "\nfail(\"deep\").";
        $source = "let problem be \"global\".\n" . self::block('procedure recurse(n)', $body, $done) . "\n" .
            self::step('let result be 100 + recurse(4).', 'let result be message of problem.', $done) . "\nreturn result.";
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $c = $p->run($source);
        self::assertSame('deep', $c->getReturnValue()); self::assertSame('global', $c->get('problem'));
        self::assertCount(1, $c->getProcess()->scopes); self::assertSame([], $c->getProcess()->scopes[0]->stack);
    }

    /** @dataProvider modes */
    public function testReturnFromBodyAndHandlerCleansStepsAndLocalBindings(bool $done): void
    {
        foreach ([false, true] as $fail) {
            $source = "let problem be \"global\".\n" . self::block('procedure run(problem)',
                self::step($fail ? 'fail("expected").' : 'return problem.', 'return message of problem.', $done), $done
            ) . "\nreturn run(\"parameter\").";
            $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $c = $p->run($source);
            self::assertSame($fail ? 'expected' : 'parameter', $c->getReturnValue());
            self::assertSame('global', $c->get('problem')); self::assertCount(1, $c->getProcess()->scopes);
            self::assertSame([], $c->getProcess()->scopes[0]->steps);
        }
    }

    /** @dataProvider nextCases */
    public function testNextTargetsNamedLoopAndPreservesSurroundingStep(bool $done, string $outer, string $inner, string $target, bool $inHandler): void
    {
        $jump = $inHandler ? self::step('fail("skip").', "next $target.", $done, 'inner step') : "next $target.";
        $body = 'let trace be trace + a + ":" + b + ";".' . "\n" . $jump . "\nfail(\"unreachable\").";
        $source = 'let trace be "".' . "\n" . self::step(
            self::block($outer, self::block($inner, $body, $done) . "\nlet trace be trace + \"end;\".", $done),
            'fail("outer must not catch").', $done, 'outer step'
        ) . "\nreturn trace.";
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $p->setMaxCycles(2000);
        $c = $p->run($source, ['items' => [1, 2]]);
        self::assertSame($target === 'a' ? '1:1;2:1;' : '1:1;1:2;end;2:1;2:2;end;', $c->getReturnValue(), $source);
        self::assertSame([], $c->getProcess()->scopes[0]->steps); self::assertFalse($c->has('problem'));
    }

    public static function nextCases(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (['from 1 to 2 as a', 'for each a in items'] as $outer) {
                foreach (['from 1 to 2 as b', 'for each b in items'] as $inner) {
                    foreach (['a', 'b'] as $target) {
                        foreach ([false, true] as $handler) { yield [$done, $outer, $inner, $target, $handler]; }
                    }
                }
            }
        }
    }

    /** @dataProvider malformed */
    public function testMalformedStepAndNextAreRejected(string $source, bool $done): void
    {
        $this->expectException(ParserException::class);
        $p = new Procer(); $p->useDoneKeyword($done); $p->run($source);
    }
    public static function malformed(): iterable
    {
        foreach ([false, true] as $done) {
            foreach (['next missing.', 'next "item".', 'next item',
                'step "a".', 'step "a" do', 'on failure as problem do nothing.',
                'step "a" do nothing. on failure as problem nothing.',
                'step "a" do nothing. on failure as "problem" do nothing.',
                'step "a" do nothing. on failure as problem do nothing. on failure as other do nothing.',
            ] as $source) { yield [$source, $done]; }
            yield [self::block('procedure jump', 'next item.', $done) . "\n" . self::block('for each item in items', 'jump().', $done), $done];
            yield [self::block('while true', 'next item.', $done), $done];
        }
    }

    /** @dataProvider fatalCases */
    public function testOnlyDeclaredOperationalFailuresAreHandled(string $body, string $class, bool $done): void
    {
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $p->setMaxCycles(30);
        $this->expectException($class);
        $p->run(self::step($body, 'return "incorrectly caught".', $done));
    }
    public static function fatalCases(): iterable
    {
        foreach ([false, true] as $done) {
            foreach ([['fatal("type").', \TypeError::class], ['fatal("runtime").', \RuntimeException::class],
                ['fatal("runner").', \Karboosx\Procer\Exception\RunnerException::class],
                ['let x be missing.', \Karboosx\Procer\Exception\VariableNotFoundException::class],
                ['missing_function().', \Karboosx\Procer\Exception\FunctionNotFoundException::class],
                [self::block('while true', 'nothing.', $done), MaxCyclesException::class]] as [$body, $class]) {
                yield [$body, $class, $done];
            }
        }
    }

    /** @dataProvider modes */
    public function testKeywordsRemainUsableAsExistingNamesAndObjectMethods(bool $done): void
    {
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done);
        $c = $p->run('let step be step(). let next be next(). let as be as(). let failure be obj. on failure as next. return step + next + as.', ['obj' => new \stdClass()]);
        self::assertSame(6, $c->getReturnValue());
        self::assertSame(2, $p->run('return next on obj.', ['obj' => new \stdClass()])->getReturnValue());
    }

    public function testUnhandledOperationalFailureCanStillBeRetried(): void
    {
        $probe = new class implements FunctionProviderInterface {
            public int $calls = 0;
            public function supports(string $name): bool { return $name === 'retry'; }
            public function retry(Context $context): int { if (++$this->calls === 1) { throw new OperationFailure('transient'); } return 8; }
        };
        $p = new Procer([$probe]);
        try { $p->run("step \"unhandled\" do\n    let result be 100 + retry().\nreturn result."); self::fail('Expected failure'); }
        catch (OperationFailure $e) { self::assertSame('transient', $e->getMessage()); }
        $c = $p->resume(); self::assertSame(108, $c->getReturnValue()); self::assertSame(2, $probe->calls);
        self::assertSame([], $c->getProcess()->scopes[0]->steps);
    }

    public function testGetterFailureCarriesOperationAndNestedStepName(): void
    {
        $object = new class { public function getValue(): never { throw new OperationFailure('getter failed'); } };
        $source = self::step(self::step('let x be value of obj.', null, false, 'read property'), 'return problem.', false);
        $c = (new Procer())->run($source, ['obj' => $object]);
        self::assertSame('value', $c->getReturnValue()->operation);
        self::assertSame('read property', $c->getReturnValue()->step);
    }
}
