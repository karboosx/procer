<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\Exception\OperationFailure;
use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowEdgeCaseTest extends TestCase
{
    /** @dataProvider modes */
    public function testHandlerCanShadowLoopTargetThroughSnapshotAndNext(bool $done): void
    {
        $source = 'let sum be 0.' . "\n" . WorkflowLanguageTest::block('from 1 to 3 as item',
            'let sum be sum + item.' . "\n" . WorkflowLanguageTest::step('fail("expected").', 'stop. next item.', $done, 'work', 'item'), $done
        ) . "\nreturn sum as \"sum\", item as \"after\".";
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $c = $p->run($source);
        for ($i = 0; !$c->isFinished(); $i++) {
            self::assertLessThan(4, $i); self::assertInstanceOf(\stdClass::class, $c->get('item'));
            $p = new Procer([new WorkflowProbe()]); $c = $p->resume((new Deserializer())->deserialize($c->serialize()));
        }
        self::assertEquals((object)['sum' => 6, 'after' => 4], $c->getReturnValue());
    }

    /** @dataProvider modes */
    public function testUnhandledFailureInHandlerIsRetryableAfterSnapshot(bool $done): void
    {
        $probe = new class implements FunctionProviderInterface {
            public int $retry = 0;
            public function supports(string $name): bool { return in_array($name, ['fail', 'retry']); }
            public function fail(Context $c): never { throw new OperationFailure('original'); }
            public function retry(Context $c): int { if (++$this->retry === 1) { throw new OperationFailure('cleanup'); } return 5; }
        };
        $p = new Procer([$probe]); $p->useDoneKeyword($done);
        $source = 'let problem be "prior".' . "\n" . WorkflowLanguageTest::step('fail().', 'stop. let result be retry().', $done) . "\nreturn result.";
        $c = $p->run($source);
        try { $p->resume(); self::fail('Cleanup failure must not reenter its handler'); }
        catch (OperationFailure $e) { self::assertSame('cleanup', $e->getMessage()); }
        $c = (new Procer([$probe]))->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertSame(5, $c->getReturnValue()); self::assertSame('prior', $c->get('problem'));
        self::assertSame(2, $probe->retry);
    }

    /** @dataProvider modes */
    public function testAHandlerMayUseItsOwnNestedRecovery(bool $done): void
    {
        $inside = WorkflowLanguageTest::step('fail("inner").', 'let inner be message of problem. stop.', $done, 'inner');
        $source = WorkflowLanguageTest::step('fail("outer").', $inside . "\nreturn message of problem as \"outer\", inner as \"inner\".", $done, 'outer');
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $c = $p->run($source);
        $c = (new Procer([new WorkflowProbe()]))->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertEquals((object)['outer' => 'outer', 'inner' => 'inner'], $c->getReturnValue());
        self::assertFalse($c->has('problem'));
    }

    public static function modes(): array { return [[false], [true]]; }

    public function testRecordAndStepNestingLimitsAreBounded(): void
    {
        foreach (['records', 'steps'] as $kind) {
            $source = $kind === 'records' ? '1' : 'nothing.';
            for ($depth = 0; $depth < 200; $depth++) {
                $source = $kind === 'records' ? '(' . $source . ' as "x")' : WorkflowLanguageTest::step($source, null, false);
            }
            if ($kind === 'records') { $source = 'return ' . $source . '.'; }
            try { (new Procer())->run($source); self::fail('Excessive nesting must fail'); }
            catch (ParserException $e) { self::assertStringContainsString('Maximum', $e->getMessage()); }
        }
    }

    public function testLargeFlatRecordPreservesAllNamesAndValues(): void
    {
        $fields = [];
        for ($i = 0; $i < 5000; $i++) { $fields[] = "$i as \"field$i\""; }
        $c = (new Procer())->run('return ' . implode(', ', $fields) . '.');
        $record = (new Procer())->resume((new Deserializer())->deserialize($c->serialize()))->getReturnValue();
        self::assertCount(5000, get_object_vars($record));
        for ($i = 0; $i < 5000; $i++) { self::assertSame($i, $record->{'field' . $i}); }
    }

    /** @dataProvider badInstructionArguments */
    public function testNewInstructionSchemasRejectCorruptSnapshots(int $opcode, array $arguments): void
    {
        $c = (new Procer())->run('stop. return 1 as "x".');
        $raw = json_decode($c->serialize(), true);
        $raw['ic']['i'][1]['o'] = $opcode; $raw['ic']['i'][1]['a'] = $arguments;
        $this->expectException(DeserializationException::class);
        (new Deserializer())->deserialize(json_encode($raw));
    }
    public static function badInstructionArguments(): iterable
    {
        foreach ([[], ['s:x', 's:x'], ['i:4'], [null], ["s:a\0b"]] as $args) { yield [InstructionType::BUILD_RECORD->value, $args]; }
        foreach ([[], ['s:label', 'i:3', 's:e'], ['s:label', 'i:0', 's:e', 'i:4'], ['s:label', 'i:9999', 's:e', 'i:10000'],
            ['s:label', 'i:3', 's:', 'i:4'], ['s:label', 'i:4', 's:e', 'i:4'], ['i:4', 'i:3', 's:e', 'i:4']] as $args) { yield [InstructionType::ENTER_STEP->value, $args]; }
        foreach ([[], ['i:1'], ['i:-1', 'i:0'], ['i:2', 'i:-1'], ['s:2', 'i:0'], ['i:999999', 'i:0']] as $args) { yield [InstructionType::NEXT_LOOP->value, $args]; }
        yield [InstructionType::LEAVE_STEP->value, ['i:1']];
    }

    public function testVersionOneRemainsReadableAndNewProgramsUseVersionTwo(): void
    {
        $old = (new Procer())->run('stop. return 7.');
        self::assertSame(1, json_decode($old->serialize(), true)['v']);
        self::assertSame(7, (new Procer())->resume((new Deserializer())->deserialize($old->serialize()))->getReturnValue());
        $new = (new Procer())->run('stop. return 7 as "value".');
        self::assertSame(2, json_decode($new->serialize(), true)['v']);
        self::assertEquals((object)['value' => 7], (new Procer())->resume((new Deserializer())->deserialize($new->serialize()))->getReturnValue());
    }

    public function testRecordInstructionDoesNotSilentlyReplaceMissingOperandsWithNull(): void
    {
        $c = (new Procer())->run('stop. return 1 as "one", 2 as "two".');
        $process = (new Deserializer())->deserialize($c->serialize());
        foreach ($process->ic->getInstructions() as $i => $instruction) {
            if ($instruction->getType() === InstructionType::BUILD_RECORD) { $process->currentInstructionIndex = $i; break; }
        }
        $this->expectException(\Karboosx\Procer\Exception\RunnerException::class);
        $this->expectExceptionMessage('Insufficient operands');
        (new Procer())->resume($process);
    }

    public function testHandlerLayoutAndOwnershipAcrossNewlineAndIndentationStyles(): void
    {
        foreach (["\n", "\r\n", "\r"] as $eol) {
            foreach (['', '  ', "\t"] as $root) {
                $source = WorkflowLanguageTest::step(WorkflowLanguageTest::block('if false', 'fail("never").', false) . "\nfail(\"outer\").",
                    'return message of problem.', false);
                $source = $root . str_replace("\n", $eol . $root, $source);
                self::assertSame('outer', (new Procer([new WorkflowProbe()]))->run($source)->getReturnValue());
            }
        }
        foreach (["step \"wrapped\"\n    do nothing.", "step \"x\" do\n    fail().\non failure as problem\n    do nothing."] as $source) {
            try { (new Procer([new WorkflowProbe()]))->run($source); self::fail('An inline body is invalid in indentation mode'); }
            catch (ParserException $e) { self::assertStringContainsString('indented block', $e->getMessage()); }
        }
    }
}
