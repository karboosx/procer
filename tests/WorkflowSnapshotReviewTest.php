<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\OperationFailure;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Serializer\DeserializeObjectProviderInterface;
use Karboosx\Procer\Serializer\Deserializer;
use Karboosx\Procer\Serializer\LazyDeserializer;
use Karboosx\Procer\Serializer\SerializableObjectInterface;
use Karboosx\Procer\Serializer\Serializer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowSnapshotReviewTest extends TestCase
{
    private static function before(string $source, InstructionType $type, array $variables = []): Process
    {
        $process = new Process(); $process->ic = (new ICParser())->parse(Parser::default()->parse($source));
        $process->scopes[0]->variables = $variables;
        for ($i = 0; $i < 100; $i++) {
            if ($process->ic->getInstruction($process->currentInstructionIndex)->getType() === $type) { return $process; }
            $p = new Procer(); $p->setMaxCycles($process->cycles + 1);
            try { $p->resume($process); } catch (MaxCyclesException) {}
        }
        self::fail('Instruction was not reached');
    }

    /** @dataProvider lazyFailures */
    public function testRecordAssemblySurvivesLazyOperandFailure(bool $handled, bool $recoverable, bool $restoreAgain): void
    {
        $source = WorkflowLanguageTest::step('let result be left as "left", right as "right".',
            $handled ? 'let diagnostic be problem. stop.' : null, false) . "\nreturn result.";
        $process = self::before($source, InstructionType::BUILD_RECORD, [
            'left' => new ReviewEntity('left'), 'right' => new ReviewEntity('right'), 'result' => 'unchanged',
        ]);
        $objects = new ReviewObjects($recoverable);
        $process = (new LazyDeserializer($objects))->deserialize((new Serializer())->serialize($process));
        $p = new Procer();
        if ($handled && $recoverable) {
            $c = $p->resume($process);
            self::assertFalse($c->isFinished()); self::assertSame('unavailable', $c->get('diagnostic')->message);
            self::assertSame('BUILD_RECORD', $c->get('diagnostic')->operation);
            self::assertSame([], $process->scopes[0]->stack);
            $process = (new LazyDeserializer($objects))->deserialize($c->serialize());
            self::assertSame('unchanged', (new Procer())->resume($process)->getReturnValue());
            self::assertSame(['left', 'right'], $objects->calls);
        } else {
            try { $p->resume($process); self::fail('Expected lazy loader failure'); }
            catch (OperationFailure|\RuntimeException $e) { self::assertSame('unavailable', $e->getMessage()); }
            self::assertSame(InstructionType::BUILD_RECORD, $process->ic->getInstruction($process->currentInstructionIndex)->getType());
            self::assertCount(2, $process->scopes[0]->stack);
            self::assertSame('unchanged', $process->scopes[0]->variables['result']);
            if ($restoreAgain) {
                $process = (new LazyDeserializer($objects))->deserialize((new Serializer())->serialize($process));
                $p = new Procer();
            }
            $record = $p->resume($process)->getReturnValue();
            self::assertSame('left', $record->left->id); self::assertSame('right', $record->right->id);
            self::assertSame($restoreAgain ? ['left', 'right', 'left', 'right'] : ['left', 'right', 'right'], $objects->calls);
            self::assertSame([], $process->scopes[0]->steps); self::assertSame([], $process->scopes[0]->stack);
        }
    }
    public static function lazyFailures(): iterable
    {
        foreach ([false, true] as $handled) { foreach ([false, true] as $recoverable) { foreach ([false, true] as $restore) {
            yield [$handled, $recoverable, $restore];
        } } }
    }

    public function testShadowedLazyBindingIsNotLoadedByHandlerEntryOrExit(): void
    {
        $source = "stop.\n" . WorkflowLanguageTest::step('fail("expected").', 'stop.', false) . "\nstop. return problem.";
        $objects = new ReviewObjects(false); $objects->fail = false;
        $c = (new Procer())->run($source, ['problem' => new ReviewEntity('prior')]);
        $process = (new LazyDeserializer($objects))->deserialize($c->serialize());
        $c = (new Procer([new WorkflowProbe()]))->resume($process);
        self::assertSame('expected', $c->get('problem')->message); self::assertSame([], $objects->calls);
        $process = (new LazyDeserializer($objects))->deserialize($c->serialize());
        $p = new Procer(); $c = $p->resume($process);
        self::assertSame([], $objects->calls, 'Restoring the old binding must not resolve it');
        self::assertSame('prior', $p->resume()->getReturnValue()->id);
        self::assertSame(['prior'], $objects->calls);
    }

    public function testFailureFromAnActiveProcedureHandlerUnwindsToCallerHandler(): void
    {
        $procedure = WorkflowLanguageTest::block('procedure child(problem)',
            WorkflowLanguageTest::step('fail("first").', 'stop. fail("second").', false, 'child'), false);
        $source = "let problem be \"global\".\n$procedure\n" . WorkflowLanguageTest::step(
            'let result be 3 as "partial", child("parameter") as "value".',
            'let diagnostic be problem. stop.', false, 'caller'
        ) . "\nreturn problem.";
        $probe = new WorkflowProbe(); $c = (new Procer([$probe]))->run($source);
        self::assertCount(2, $c->getProcess()->scopes);
        $c = (new Procer([$probe]))->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertCount(1, $c->getProcess()->scopes); self::assertSame([], $c->getProcess()->scopes[0]->stack);
        self::assertSame('second', $c->get('diagnostic')->message); self::assertSame('child', $c->get('diagnostic')->step);
        $c = (new Procer([$probe]))->resume((new Deserializer())->deserialize($c->serialize()));
        self::assertSame('global', $c->getReturnValue()); self::assertSame(['first', 'second'], $probe->trace);
    }

    public function testAbsentSourceInformationProducesNullDiagnosticPositions(): void
    {
        $process = new Process();
        $compiled = (new ICParser())->parse(Parser::default()->parse(WorkflowLanguageTest::step('fail("expected").', 'return problem.', false)));
        $instructions = array_map(static fn($instruction) => new ICInstruction($instruction->getType(), $instruction->getArgs(), null), $compiled->getInstructions());
        $process->ic = new \Karboosx\Procer\IC\IC($instructions, $compiled->getProcedurePointers());
        $diagnostic = (new Procer([new WorkflowProbe()]))->resume($process)->getReturnValue();
        self::assertNull($diagnostic->line); self::assertNull($diagnostic->column); self::assertSame('fail', $diagnostic->operation);
    }

    public static function invalidFrameDepths(): array { return [[InstructionType::LEAVE_STEP, []], [InstructionType::NEXT_LOOP, [1, 1]]]; }
    /** @dataProvider invalidFrameDepths */
    public function testInvalidRuntimeStepDepthFailsCleanly(InstructionType $type, array $args): void
    {
        $process = new Process(); $process->ic = new \Karboosx\Procer\IC\IC([new ICInstruction($type, $args, null)], []);
        $p = new Procer();
        try { $p->resume($process); self::fail('Expected invalid depth error'); }
        catch (RunnerException $e) { self::assertSame('Invalid active step depth', $e->getMessage()); }
        self::assertSame(1, $p->run('return 1.')->getReturnValue());
    }

    public static function versionDowngrades(): iterable
    {
        yield ['return 1 as "field".'];
        yield [WorkflowLanguageTest::step('nothing.', null, false)];
        yield [WorkflowLanguageTest::block('from 1 to 2 as i', 'next i.', false)];
    }
    /** @dataProvider versionDowngrades */
    public function testNewBytecodeCannotMasqueradeAsVersionOne(string $source): void
    {
        $c = (new Procer())->run($source); $raw = json_decode($c->serialize(), true); $raw['v'] = 1;
        foreach ($raw['s'] as &$scope) { unset($scope['h']); } unset($scope);
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('new instructions require snapshot format version 2');
        (new Deserializer())->deserialize(json_encode($raw));
    }

    public function testExcessiveSnapshotFrameCountIsRejectedBeforeTraversal(): void
    {
        $c = (new Procer())->run(WorkflowLanguageTest::step('stop.', null, false));
        $raw = json_decode($c->serialize(), true); $raw['s'][0]['h'] = array_fill(0, 129, $raw['s'][0]['h'][0]);
        $this->expectException(DeserializationException::class); $this->expectExceptionMessage('too many step frames');
        (new Deserializer())->deserialize(json_encode($raw));
    }

    /** @dataProvider malformedFrameFields */
    public function testEachFrameFieldRejectsInvalidTypes(string $field, mixed $value): void
    {
        $c = (new Procer())->run(WorkflowLanguageTest::step('stop.', null, false));
        $raw = json_decode($c->serialize(), true); $raw['s'][0]['h'][0][$field] = $value;
        $this->expectException(DeserializationException::class);
        (new Deserializer())->deserialize(json_encode($raw));
    }
    public static function malformedFrameFields(): iterable
    {
        foreach (['instruction', 'stack', 'handling', 'hadBinding'] as $field) {
            foreach ([null, [], 's:bad', 'd:1', ['i:0']] as $value) { yield [$field, $value]; }
        }
        foreach (['instruction', 'stack'] as $field) { foreach (['i:-1', 'i:9999999', 'b:0'] as $value) { yield [$field, $value]; } }
        foreach (['handling', 'hadBinding'] as $field) { yield [$field, 'i:1']; }
        yield ['bindingValue', 'i:1'];
        yield ['extra', null];
    }
}

class ReviewEntity implements SerializableObjectInterface
{
    public function __construct(public string $id) {}
    public function getSerializeId(): string { return $this->id; }
}

class ReviewObjects implements DeserializeObjectProviderInterface
{
    public array $calls = [];
    public bool $fail = true;
    public function __construct(private bool $recoverable) {}
    public function supports(string $objectId): bool { return true; }
    public function deserialize(string $objectId): mixed
    {
        $this->calls[] = $objectId;
        if ($objectId === 'right' && $this->fail) {
            $this->fail = false;
            throw $this->recoverable ? new OperationFailure('unavailable') : new \RuntimeException('unavailable');
        }
        return new ReviewEntity($objectId);
    }
}
