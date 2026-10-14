<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\IcParserException;
use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Exception\ProcerException;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use PHPUnit\Framework\TestCase;

/**
 * Seeded property tests: failures include the exact seed, case and source.
 * PROCER_FUZZ_CASES controls iterations per randomized campaign (default 1000).
 * PROCER_FUZZ_SEED selects a reproducible campaign without touching PHP's global RNG.
 * @group stress
 */
class ParserStressTest extends TestCase
{
    private int $state;
    private int $seed;

    protected function setUp(): void
    {
        $this->state = $this->seed = (int)(getenv('PROCER_FUZZ_SEED') ?: 20261014) & 0xffffffff;
    }

    private function cases(): int
    {
        return max(1, min(100000, (int)(getenv('PROCER_FUZZ_CASES') ?: 1000)));
    }

    private function next(int $max): int
    {
        $this->state = (1664525 * $this->state + 1013904223) & 0xffffffff;
        return (int)(($this->state >> 8) % $max);
    }

    private function describe(int $case, string $source): string
    {
        return 'seed=' . $this->seed . ' case=' . $case . ' source=' . json_encode($source, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function testGeneratedArithmeticMatchesIndependentEvaluation(): void
    {
        $procer = new Procer();
        $procer->setMaxCycles(20000);
        for ($i = 0; $i < $this->cases(); $i++) {
            [$source, $expected] = $this->arithmetic(4);
            self::assertSame($expected, $procer->runExpression($source), $this->describe($i, $source));
            self::assertSame($expected, $procer->run('return ' . $source . '.')->getReturnValue(), $this->describe($i, $source));
        }
    }

    /** Construct the expected value with the grammar, without eval or the parser. */
    private function arithmetic(int $depth): array
    {
        if ($depth === 0 || $this->next(4) === 0) {
            $number = $this->next(21);
            return [(string)$number, $number];
        }
        if ($this->next(5) === 0) {
            [$source, $value] = $this->arithmetic($depth - 1);
            return ['-(' . $source . ')', -$value];
        }
        [$left, $a] = $this->arithmetic($depth - 1);
        [$right, $b] = $this->arithmetic($depth - 1);
        $operator = ['+', '-', '*'][$this->next(3)];
        $value = match ($operator) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b };
        $space = ['', ' ', "\t", "\n", " // comment\r\n"][$this->next(5)];
        return ['(' . $left . $space . $operator . $space . $right . ')', $value];
    }

    public function testGeneratedPrecedenceAndAssociativity(): void
    {
        $procer = new Procer();
        for ($i = 0; $i < $this->cases(); $i++) {
            [$a, $b, $c, $d] = [$this->next(20), $this->next(20), $this->next(20), $this->next(20)];
            $source = "$a - $b * $c + $d";
            self::assertSame($a - $b * $c + $d, $procer->runExpression($source), $this->describe($i, $source));
            $source = "$a > $b or $c < $d and $a is not $d";
            self::assertSame($a > $b || ($c < $d && $a != $d), $procer->runExpression($source), $this->describe($i, $source));
        }
    }

    public function testGeneratedControlFlowAgreesAcrossBlockModesAndSnapshotResume(): void
    {
        for ($i = 0; $i < $this->cases(); $i++) {
            $limit = $this->next(12);
            $branch = $this->next(2) === 1;
            $condition = $branch ? 'true' : 'false';
            $expected = $branch ? ($limit * ($limit + 1)) : 7;
            $indent = "procedure twice(n) do\n    return n * 2.\nlet sum be 0.\nif $condition do\n    from 1 to $limit as i do\n        let sum be sum + twice(i).\nif not do\n    let sum be 7.\nstop.\nreturn sum.";
            $done = "procedure twice(n) do return n * 2. done let sum be 0. if $condition do from 1 to $limit as i do let sum be sum + twice(i). done if not do let sum be 7. done stop. return sum.";
            foreach ([false => $indent, true => $done] as $mode => $source) {
                $procer = new Procer();
                $procer->setMaxCycles(10000);
                $procer->useDoneKeyword((bool)$mode);
                $context = $procer->run($source);
                self::assertSame($expected, $context->get('sum'), $this->describe($i, $source));
                self::assertFalse($context->isFinished());
                $process = (new Deserializer())->deserialize($context->serialize());
                $context = $procer->resume($process);
                self::assertSame($expected, $context->getReturnValue(), $this->describe($i, $source));
                self::assertTrue($context->isFinished());
                self::assertCount(1, $process->scopes);
                self::assertSame([], $process->scopes[0]->stack);
            }
        }
    }

    public function testEveryByteAtLexicalBoundaries(): void
    {
        for ($byte = 0; $byte < 256; $byte++) {
            foreach (['let x be %s.', '%s', 'f(1%s2).', "nothing.\n%s"] as $template) {
                $this->assertControlledCompilation(sprintf($template, chr($byte)), $byte);
            }
        }
    }

    public function testEveryTruncationOfRepresentativePrograms(): void
    {
        foreach ($this->corpus() as $source) {
            for ($i = 0; $i <= strlen($source); $i++) {
                $this->assertControlledCompilation(substr($source, 0, $i), $i);
            }
        }
    }

    public function testSeededTokenAndByteMutations(): void
    {
        $corpus = $this->corpus();
        $fragments = ['let', 'if', 'or', 'if not do', 'done', 'procedure', 'return', 'on', 'of', 'is', 'not', 'exists', 'signal', 'wait for all signals', '(', ')', '.', ',', '-', '+', '/', '%', '"', '\\', "\r\n", "\0", '@', '999999999999999999999999'];
        for ($i = 0; $i < $this->cases(); $i++) {
            $source = $corpus[$this->next(count($corpus))];
            for ($mutation = 0, $n = 1 + $this->next(5); $mutation < $n; $mutation++) {
                $offset = $this->next(strlen($source) + 1);
                $insert = $fragments[$this->next(count($fragments))];
                $source = substr_replace($source, $this->next(3) === 0 ? '' : $insert, $offset, $this->next(5));
            }
            $this->assertControlledCompilation($source, $i);
        }
    }

    public function testSeededArbitraryBytes(): void
    {
        for ($i = 0; $i < $this->cases(); $i++) {
            $source = '';
            for ($j = 0, $length = $this->next(256); $j < $length; $j++) {
                $source .= chr($this->next(256));
            }
            $this->assertControlledCompilation($source, $i);
        }
    }

    private function corpus(): array
    {
        return [
            'let x be 1 + (2 * -3). return x.',
            'procedure f(a, b) do return a + b. done let x be f(1, f(2, 3)).',
            'if signal is ready do on obj do f(1, 2). or x exists do nothing. if not do stop. done',
            'from 1 to 3 by 1 as i do for each x in items do nothing. done done',
            'while stopping x < 2 do let x be x + 1. done until true do nothing. done',
            "if true do\n    if false do\n        nothing.\nif not do\n    stop.",
            'wait for all signals red, green. let x be name of user of account.',
            'let x be "a // comment in string". // real comment',
            'on obj f(1, on other do g()). f on obj.',
        ];
    }

    private function assertControlledCompilation(string $source, int $case): void
    {
        foreach ([false, true] as $done) {
            foreach ([false, true] as $expression) {
                try {
                    $parser = new Parser(new Tokenizer(), $done);
                    $compiler = new ICParser();
                    $ast = $expression ? $parser->parseExpression($source) : $parser->parse($source);
                    $ic = $expression ? $compiler->parseExpression($ast) : $compiler->parse($ast);
                    $this->assertValidBytecode($ic, $this->describe($case, $source));
                    $procer = new Procer();
                    $procer->useDoneKeyword($done);
                    $procer->setMaxCycles(200);
                    try {
                        $variables = ['x' => 1, 'items' => [1, 2], 'obj' => new \stdClass()];
                        $expression ? $procer->runExpression($source, $variables) : $procer->run($source, $variables);
                    } catch (ProcerException $expectedRuntimeError) {
                        self::assertNotSame('', $expectedRuntimeError->getMessage());
                    }
                } catch (ParserException|IcParserException $expected) {
                    self::assertNotSame('', $expected->getMessage());
                } catch (\Throwable $unexpected) {
                    self::fail($this->describe($case, $source) . ' mode=' . (int)$done . ' expression=' . (int)$expression . ' ' . $unexpected);
                }
            }
        }
    }

    private function assertValidBytecode(IC $ic, string $message): void
    {
        $count = count($ic->getInstructions());
        foreach ($ic->getInstructions() as $instruction) {
            $args = $instruction->getArgs();
            $target = match ($instruction->getType()) {
                InstructionType::JMP, InstructionType::IF_NOT_JMP => $args[0],
                InstructionType::FUNCTION_CALL => $args[2],
                InstructionType::OBJECT_FUNCTION_CALL => $args[3],
                default => null,
            };
            if ($target !== null) {
                self::assertIsInt($target, $message);
                self::assertGreaterThanOrEqual(0, $target, $message);
                self::assertLessThanOrEqual($count, $target, $message);
            }
        }
        self::assertTrue(true); // Includes valid empty programs.
    }
}
