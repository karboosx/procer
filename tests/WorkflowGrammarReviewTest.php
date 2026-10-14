<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Exception\SerializationException;
use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowGrammarReviewTest extends TestCase
{
    public static function malformed(): iterable
    {
        foreach ([false, true] as $done) {
            $block = fn($header, $body) => WorkflowLanguageTest::block($header, $body, $done);
            $step = fn($body, $handler = null) => WorkflowLanguageTest::step($body, $handler, $done);
            foreach ([
                $block('from 1 to 2 as i', 'next i, i.'),
                $block('from 1 to 2 as i', 'next i().'),
                $block('from 1 to 2 as i', 'next i + 1.'),
                $block('from 1 to 2 as i', 'next i as "x".'),
                $block('from 1 to 2 as i', 'next I.'),
                $block('from 1 to 2 as _i', 'nothing.'),
                $block('from 1 to 2', 'next i.'),
                'let i be 1. next i.',
                $block('from 1 to 2 as i', 'nothing.') . "\nnext i.",
                $step($block('from 1 to 2 as i', 'fail("expected").'), 'next i.'),
                $step('let x be 1 as "a", 2 as "a".', 'nothing.'),
                $step('let x be 1 as "a" 2 as "b".', 'nothing.'),
                'let x be (1 as "a",).',
                'let x be (1 as "a") as field.',
                'let x be 1 as "a": 2.',
                'let x be 1 as "a", 2 as.',
                'let x be 1 as "a", 2 as null.',
                'let x be {}.', 'let x be [].',
                'step name do nothing.' . ($done ? ' done' : ''),
                'step ("name") do nothing.' . ($done ? ' done' : ''),
            ] as $i => $source) {
                yield "$i/$done" => [$source, $done];
            }
            if ($done) { yield [$step('nothing.') . "\non failure as error do\n    nothing.\ndone", true]; }
            foreach (['on failure as do', 'on failure as "x" do', 'on failure as x, y do', 'on failure as x do do'] as $header) {
                yield ["step \"work\" do\n    nothing.\n$header\n    nothing." . ($done ? "\ndone" : ''), $done];
            }
        }
    }

    /** @dataProvider malformed */
    public function testInvalidSyntaxAndOutOfScopeTargetsFailBeforeExecution(string $source, bool $done): void
    {
        $probe = new WorkflowProbe(); $p = new Procer([$probe]); $p->useDoneKeyword($done);
        try { $p->run($source); self::fail('Invalid syntax must fail'); }
        catch (ParserException $error) { self::assertNotSame('', $error->getMessage()); }
        self::assertSame([], $probe->trace);
        self::assertSame(7, $p->run('return 7.')->getReturnValue());
    }

    public function testCompilerCanBeReusedAfterAnInvalidNextInsideAStep(): void
    {
        $compiler = new ICParser();
        try { $compiler->parse(Parser::default()->parse(WorkflowLanguageTest::step('next missing.', null, false))); self::fail('Invalid target'); }
        catch (ParserException) {}
        $source = WorkflowLanguageTest::block('from 1 to 2 as i', WorkflowLanguageTest::step('next i.', null, false), false) . "\nreturn i.";
        $process = new \Karboosx\Procer\Runner\Process(); $process->ic = $compiler->parse(Parser::default()->parse($source));
        self::assertSame(3, (new Procer())->resume($process)->getReturnValue());
        $process = new \Karboosx\Procer\Runner\Process(); $process->ic = $compiler->parseExpression(Parser::default()->parseExpression('3 as "x"'));
        $p = new Procer(); $p->resume($process);
        self::assertEquals((object)['x' => 3], $process->scopes[0]->popStack());
    }

    public static function labelValues(): iterable
    {
        foreach (['', '0', '00', '-0', '+0', '1.0', '1e3', '-1', '9223372036854775808', '__proto__', '__construct',
            'constructor', 'on failure as x do', "quote\"slash\\", "line\nbreak", "tab\there", 'ęść😀'] as $name) { yield [$name]; }
    }

    /** @dataProvider labelValues */
    public function testUnusualRecordAndStepLabelsRemainExact(string $name): void
    {
        $literal = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
        $source = "step \"$literal\" do\n    fail(\"expected\").\non failure as problem do\n    return step of problem as \"$literal\".";
        $c = (new Procer([new WorkflowProbe()]))->run($source);
        self::assertSame($name, $c->getReturnValue()->{$name});
        $c = (new Procer())->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertSame([$name => $name], (array)$c->getReturnValue());
    }

    public function testRecordLabelsAreComparedAfterUnescaping(): void
    {
        // Unknown escapes remain literal, so these two spellings produce the same backslash-plus-q key.
        $source = 'return 1 as "\\q", 2 as "\\\\q".';
        $this->expectException(ParserException::class); $this->expectExceptionMessage('Duplicate record field name');
        (new Procer())->run($source);
    }

    public function testNonUtf8RecordKeyProducesLibrarySerializationErrorWithoutChangingResult(): void
    {
        $key = "bad\xff"; $c = (new Procer())->run('return 7 as "' . $key . '".');
        try { $c->serialize(); self::fail('Invalid UTF-8 must fail JSON encoding'); }
        catch (SerializationException $e) { self::assertNotSame('', $e->getMessage()); }
        self::assertSame(7, $c->getReturnValue()->{$key});
    }

    public function testFailureToSerializeSavedBindingDoesNotDamageHandler(): void
    {
        $old = fopen('php://memory', 'r+');
        try {
            $source = WorkflowLanguageTest::step('fail("expected").', 'stop.', false) . "\nreturn problem.";
            $p = new Procer([new WorkflowProbe()]); $c = $p->run($source, ['problem' => $old]);
            try { $c->serialize(); self::fail('Resources cannot be serialized'); }
            catch (SerializationException $e) { self::assertStringContainsString('resource', $e->getMessage()); }
            self::assertSame('expected', $c->get('problem')->message);
            self::assertSame($old, $p->resume()->getReturnValue());
        } finally { fclose($old); }
    }

    public function testReturnedErrorShapedRecordsDoNotTriggerRecovery(): void
    {
        $p = new Procer([new WorkflowProbe()]);
        $c = $p->run(WorkflowLanguageTest::step('let value be false as "ok", "just data" as "error".', 'fail("unexpected").', false) . "\nreturn value.");
        self::assertEquals((object)['ok' => false, 'error' => 'just data'], $c->getReturnValue());
    }

    /** @dataProvider blockModes */
    public function testFailureObjectCallsAreNotMistakenForIncompleteHandlers(bool $done): void
    {
        $provider = new class implements \Karboosx\Procer\ObjectFunctionProviderInterface {
            public int $calls = 0;
            public function supports(object $object, string $name): bool { return $name === 'nothing'; }
            public function nothing(\Karboosx\Procer\Context $context, object $object): void { $this->calls++; }
        };
        // Existing object syntax permits an optional verb and wrapping across lines.
        // Only the complete 'on failure as name do' sequence starts a handler.
        $source = WorkflowLanguageTest::step('nothing.', null, $done) . "\non failure do\n    nothing.";
        $p = new Procer([$provider]); $p->useDoneKeyword($done);
        $c = $p->run($source, ['failure' => new \stdClass()]);
        self::assertTrue($c->isFinished()); self::assertSame(1, $provider->calls);
    }
    public static function blockModes(): array { return [[false], [true]]; }
}
