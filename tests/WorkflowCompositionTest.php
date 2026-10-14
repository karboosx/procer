<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\ProcerException;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Tests\Helper\WorkflowProbe;
use PHPUnit\Framework\TestCase;

class WorkflowCompositionTest extends TestCase
{
    private static function wrap(string $kind, string $body, string $name, bool $done): string
    {
        $block = fn($header, $inner) => WorkflowLanguageTest::block($header, $inner, $done);
        return match ($kind) {
            'if' => $block('if true', "let $name be 1.\n" . $body),
            'skip' => $block('if false', "let $name be 1.\n" . $body),
            'or' => "if false do\n    nothing.\nor true do\n" . WorkflowLanguageTest::indent("let $name be 2.\n" . $body) . ($done ? "\ndone" : ''),
            'else' => "if false do\n    nothing.\nif not do\n" . WorkflowLanguageTest::indent("let $name be 3.\n" . $body) . ($done ? "\ndone" : ''),
            'range' => $block("from 1 to 2 as $name", $body),
            'descending' => $block("from 2 to 1 by -1 as $name", $body),
            'each' => $block("for each $name in items", $body),
            'while' => "let $name be 0.\n" . $block("while $name < 2", "let $name be $name + 1.\n" . $body),
            'until' => "let $name be 0.\n" . $block("until $name >= 2", "let $name be $name + 1.\n" . $body),
        };
    }
    private static function values(string $kind): array
    {
        return match ($kind) { 'if' => [1], 'skip' => [], 'or' => [2], 'else' => [3], 'descending' => [2, 1], 'each' => [4, 2], default => [1, 2] };
    }
    public static function pairs(): iterable
    {
        $forms = ['if', 'skip', 'or', 'else', 'range', 'descending', 'each', 'while', 'until'];
        foreach ($forms as $a) { foreach ($forms as $b) { foreach ([false, true] as $done) { foreach ([false, true] as $failure) {
            yield "$a / $b / $done / $failure" => [$a, $b, $done, $failure];
        } } } }
    }

    /** @dataProvider pairs */
    public function testStepsAndRecordsAcrossAllBlockPairs(string $outer, string $inner, bool $done, bool $failure): void
    {
        $append = 'let record be a as "a", b as "b". let trace be trace + a of record + ":" + b of record + ";".';
        $body = WorkflowLanguageTest::step($failure ? 'fail("planned"). fail("unreachable").' : $append,
            $failure ? $append : 'fail("unexpected handler").', $done);
        $source = 'let trace be "".' . "\n" . self::wrap($outer, self::wrap($inner, $body, 'b', $done), 'a', $done) . "\nreturn trace.";
        $expected = '';
        foreach (self::values($outer) as $a) { foreach (self::values($inner) as $b) { $expected .= "$a:$b;"; } }
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $p->setMaxCycles(3000);
        $context = $p->run($source, ['items' => [4, 2]]);
        self::assertSame($expected, $context->getReturnValue(), $source);
        self::assertSame([], $context->getProcess()->scopes[0]->steps);
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }

    public function testStepsAcrossAllTripleNestingPositions(): void
    {
        foreach ([false, true] as $done) {
            foreach ([false, true] as $outerHandler) {
                foreach ([false, true] as $middleHandler) {
                    foreach ([false, true] as $innerHandler) {
                        $body = 'fail("origin").';
                        foreach (['inner' => $innerHandler, 'middle' => $middleHandler, 'outer' => $outerHandler] as $name => $handler) {
                            $body = WorkflowLanguageTest::step($body, $handler ? 'let caught be "' . $name . '".' : null, $done, $name);
                        }
                        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done);
                        if (!$outerHandler && !$middleHandler && !$innerHandler) {
                            try { $p->run($body); self::fail('Failure must propagate'); }
                            catch (\Karboosx\Procer\Exception\OperationFailure $e) { self::assertSame('origin', $e->getMessage()); }
                        } else {
                            self::assertSame($innerHandler ? 'inner' : ($middleHandler ? 'middle' : 'outer'), $p->run($body)->get('caught'));
                        }
                    }
                }
            }
        }
    }

    /** @group stress */
    public function testGeneratedRecordsAndFailureSchedulesHaveIndependentOracles(): void
    {
        $seed = (int)(getenv('PROCER_FUZZ_SEED') ?: 20261014);
        $state = $seed & 0x7fffffff;
        $random = static function (int $limit) use (&$state): int { $state = (int)(($state * 1103515245 + 12345) & 0x7fffffff); return $state % $limit; };
        $count = max(1, min(100000, (int)(getenv('PROCER_FUZZ_CASES') ?: 1000)));
        for ($case = 0; $case < $count; $case++) {
            $fields = []; $expected = new \stdClass();
            for ($n = 0, $length = $random(8) + 1; $n < $length; $n++) {
                $a = $random(101) - 50; $b = $random(21) - 10;
                $key = $n % 2 === 0 ? "field$n" : (string)$n;
                $fields[] = "($a * $b + $n) as \"$key\"";
                $expected->{$key} = $a * $b + $n;
            }
            $record = implode(', ', $fields);
            $limit = $random(6) + 1; $failAt = $random($limit + 1); $total = 0; $rejected = 0;
            for ($i = 1; $i <= $limit; $i++) { if ($i === $failAt) { $rejected++; } else { $total += $i; } }
            foreach ([false, true] as $done) {
                $source = "let total be 0. let rejected be 0. let record be $record.\n" . WorkflowLanguageTest::block("from 1 to $limit as i",
                    WorkflowLanguageTest::step(WorkflowLanguageTest::block("if i is $failAt", 'fail("planned").', $done) . "\nlet total be total + i.",
                        'let rejected be rejected + 1. next i.', $done), $done) . "\nreturn record as \"record\", total as \"total\", rejected as \"rejected\".";
                $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $p->setMaxCycles(2000);
                $result = $p->run($source);
                self::assertEquals((object)['record' => $expected, 'total' => $total, 'rejected' => $rejected], $result->getReturnValue(), "seed=$seed case=$case done=$done\n$source");
                self::assertSame([], $result->getProcess()->scopes[0]->stack);
                self::assertSame([], $result->getProcess()->scopes[0]->steps);
            }
        }
    }

    /** @group stress */
    public function testEveryTruncationAndByteMutationOfNewSyntaxIsBounded(): void
    {
        $programs = [
            'let entry be 1 as "one", (2 as "two") as "nested". return entry.',
            WorkflowLanguageTest::block('from 1 to 2 as item', WorkflowLanguageTest::step('fail("x").', 'next item.', false), false),
            WorkflowLanguageTest::block('for each item in items', WorkflowLanguageTest::step('fail("x").', 'let result be message of problem as "message". next item.', true), true),
        ];
        foreach ($programs as $index => $source) {
            $done = $index === 2;
            for ($cut = 0; $cut <= strlen($source); $cut++) { $this->assertBounded(substr($source, 0, $cut), $done); }
            foreach ([' as ', ' do', 'next ', 'on failure', ','] as $delimiter) {
                $position = strpos($source, $delimiter);
                if ($position === false) { continue; }
                for ($byte = 0; $byte < 256; $byte++) {
                    $this->assertBounded(substr_replace($source, chr($byte), $position, 0), $done);
                    $this->assertBounded(substr_replace($source, chr($byte), $position, 1), $done);
                }
            }
        }
    }
    private function assertBounded(string $source, bool $done): void
    {
        $p = new Procer([new WorkflowProbe()]); $p->useDoneKeyword($done); $p->setMaxCycles(300);
        try { $p->run($source, ['items' => [1, 2]]); self::assertTrue(true); }
        catch (ProcerException $expected) { self::assertNotSame('', $expected->getMessage()); }
    }
}
