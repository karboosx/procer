<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class LanguageCompositionTest extends TestCase
{
    /** @dataProvider blockPairs */
    public function testEveryPairOfBlockFormsPreservesIterationOrder(string $outer, string $inner, bool $done): void
    {
        $body = 'let trace be trace + left + ":" + right + ";".';
        $source = 'let trace be "".' . "\n" . self::wrap($outer, self::wrap($inner, $body, 'right', $done), 'left', $done) . "\nreturn trace.";
        $expected = '';
        foreach (self::values($outer) as $left) {
            foreach (self::values($inner) as $right) { $expected .= "$left:$right;"; }
        }
        $procer = new Procer();
        $procer->useDoneKeyword($done);
        $procer->setMaxCycles(10000);
        $context = $procer->run($source, ['items' => [4, 1, 7]]);
        self::assertSame($expected, $context->getReturnValue(), $source);
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }

    /** @dataProvider blockPairs */
    public function testReturnEscapesEveryPairOfNestedBlocks(string $outer, string $inner, bool $done): void
    {
        $body = self::wrap($outer, self::wrap($inner, 'return left + ":" + right.', 'right', $done), 'left', $done);
        $source = "let selected be pick().\nprocedure pick do\n" . self::indent($body . "\nreturn \"empty\".") . ($done ? "\ndone" : '') . "\nreturn selected + \":done\".";
        $left = self::values($outer);
        $right = self::values($inner);
        $expected = $left && $right ? $left[0] . ':' . $right[0] . ':done' : 'empty:done';
        $procer = new Procer();
        $procer->useDoneKeyword($done);
        $procer->setMaxCycles(10000);
        $context = $procer->run($source, ['items' => [4, 1, 7]]);
        self::assertSame($expected, $context->getReturnValue(), $source);
        self::assertCount(1, $context->getProcess()->scopes);
        self::assertFalse($context->has('left'));
        self::assertFalse($context->has('right'));
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }

    public static function blockPairs(): iterable
    {
        $forms = ['if', 'skip', 'or', 'else', 'ascending', 'descending', 'each', 'while', 'until'];
        foreach ($forms as $outer) {
            foreach ($forms as $inner) {
                foreach ([false, true] as $done) {
                    yield "$outer / $inner / " . ($done ? 'done' : 'indent') => [$outer, $inner, $done];
                }
            }
        }
    }

    public function testAllThreeLevelBlockCombinations(): void
    {
        $forms = ['if', 'skip', 'or', 'else', 'ascending', 'descending', 'each', 'while', 'until'];
        $procer = new Procer();
        $procer->setMaxCycles(20000);
        foreach ($forms as $outer) {
            foreach ($forms as $middle) {
                foreach ($forms as $inner) {
                    $expected = '';
                    foreach (self::values($outer) as $a) {
                        foreach (self::values($middle) as $b) {
                            foreach (self::values($inner) as $c) { $expected .= "$a:$b:$c;"; }
                        }
                    }
                    foreach ([false, true] as $done) {
                        $body = 'let trace be trace + a + ":" + b + ":" + c + ";".';
                        $body = self::wrap($inner, $body, 'c', $done);
                        $body = self::wrap($middle, $body, 'b', $done);
                        $source = 'let trace be "".' . "\n" . self::wrap($outer, $body, 'a', $done) . "\nreturn trace.";
                        $procer->useDoneKeyword($done);
                        self::assertSame($expected, $procer->run($source, ['items' => [4, 1, 7]])->getReturnValue(), $source);
                    }
                }
            }
        }
    }

    private static function values(string $kind): array
    {
        return match ($kind) {
            'if' => [1], 'skip' => [], 'or' => [2], 'else' => [3],
            'ascending' => [1, 2, 3], 'descending' => [3, 2, 1],
            'each' => [4, 1, 7], 'while', 'until' => [1, 2],
        };
    }

    private static function wrap(string $kind, string $body, string $name, bool $done): string
    {
        $end = $done ? "\ndone" : '';
        return match ($kind) {
            'if', 'skip' => 'if ' . ($kind === 'if' ? 'true' : 'false') . " do\n" . self::indent("let $name be 1.\n" . $body) . $end,
            'or' => "if false do\n    missing_branch().\nor false do\n    missing_branch().\nor true do\n" . self::indent("let $name be 2.\n" . $body) . "\nif not do\n    missing_branch()." . $end,
            'else' => "if false do\n    missing_branch().\nif not do\n" . self::indent("let $name be 3.\n" . $body) . $end,
            'ascending' => "from 1 to 3 as $name do\n" . self::indent($body) . $end,
            'descending' => "from 3 to 1 by -1 as $name do\n" . self::indent($body) . $end,
            'each' => "for each $name in items do\n" . self::indent($body) . $end,
            'while', 'until' => "let $name be 0.\n$kind " . ($kind === 'while' ? "$name < 2" : "$name >= 2") . " do\n" . self::indent("let $name be $name + 1.\n" . $body) . $end,
        };
    }

    private static function indent(string $source): string
    {
        return '    ' . str_replace("\n", "\n    ", $source);
    }

    /** @dataProvider objectCallContexts */
    public function testObjectCallSpellingsComposeWithSurroundingGrammar(string $source, mixed $expected, bool $done): void
    {
        $objects = new class implements ObjectFunctionProviderInterface {
            public function supports(object $object, string $name): bool { return $name === 'number'; }
            public function number(Context $context, object $object): int { return 2; }
        };
        $functions = new class implements FunctionProviderInterface {
            public function supports(string $name): bool { return $name === 'digits'; }
            public function digits(Context $context, int $a, int $b, int $c): int { return 100 * $a + 10 * $b + $c; }
        };
        $procer = new Procer([$objects, $functions]);
        $procer->useDoneKeyword($done);
        $procer->setMaxCycles(1000);
        self::assertSame($expected, $procer->run($source, ['obj' => (object)['value' => 5]])->getReturnValue(), $source);
    }

    public static function objectCallContexts(): iterable
    {
        $forms = ['on obj number()', 'on obj number', 'on obj do number()', 'on obj do number', 'on obj run number()', 'on obj run number', 'number() on obj', 'number on obj'];
        foreach ($forms as $call) {
            foreach ([false, true] as $done) {
                $end = $done ? "\ndone" : '';
                $contexts = [
                    'value' => ["return $call.", 2],
                    'negate' => ["return -($call).", -2],
                    'prefix negate' => ["return -$call.", -2],
                    'not' => ["return not $call.", false],
                    'precedence' => ["return 1 + $call * 3.", 7],
                    'comparison' => ["return $call is not (3).", true],
                    'and' => ["return $call and (true).", true],
                    'or' => ["return $call or (false).", true],
                    'property' => ["return value of obj + $call.", 7],
                    'arguments' => ["return digits($call, $call, $call).", 222],
                    'condition' => ["if $call is 2 do\n    return 7.$end\nreturn 0.", 7],
                    'range start' => ["let sum be 0.\nfrom $call to (3) as i do\n    let sum be sum + i.$end\nreturn sum.", 5],
                    'range end' => ["let sum be 0.\nfrom 1 to $call by (1) as i do\n    let sum be sum + i.$end\nreturn sum.", 3],
                ];
                foreach ($contexts as $name => [$source, $expected]) {
                    yield "$call / $name / " . ($done ? 'done' : 'indent') => [$source, $expected, $done];
                }
            }
        }
    }

    public function testEveryBinaryOperatorPairMatchesDocumentedPrecedence(): void
    {
        $precedence = ['*' => 5, '/' => 5, '%' => 5, '+' => 4, '-' => 4, '=' => 3, 'is' => 3, '!=' => 3, 'is not' => 3, '<' => 3, '>' => 3, '<=' => 3, '>=' => 3, 'and' => 2, 'or' => 1];
        $procer = new Procer();
        foreach ($precedence as $first => $p1) {
            foreach ($precedence as $second => $p2) {
                foreach ([[8, 3, 2], [2, 3, 8], [-2, 5, 3], [5.5, 2, 3], [0, 2, 1]] as [$a, $b, $c]) {
                    $source = "$a $first $b $second $c";
                    $errorExpected = false;
                    try {
                        $expected = $p1 >= $p2
                            ? self::binary($second, fn() => self::binary($first, fn() => $a, fn() => $b), fn() => $c)
                            : self::binary($first, fn() => $a, fn() => self::binary($second, fn() => $b, fn() => $c));
                    } catch (\DivisionByZeroError) {
                        $errorExpected = true;
                    }
                    try {
                        $actual = $procer->runExpression($source);
                        self::assertFalse($errorExpected, 'Expected division-by-zero rejection: ' . $source);
                        self::assertSame($expected, $actual, $source);
                    } catch (RunnerException $error) {
                        self::assertTrue($errorExpected, $source . ': ' . $error->getMessage());
                    }
                }
            }
        }
    }

    private static function binary(string $operator, \Closure $left, \Closure $right): mixed
    {
        $a = $left();
        if ($operator === 'and') { return $a && $right(); }
        if ($operator === 'or') { return $a || $right(); }
        $b = $right();
        return match ($operator) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, '/' => $a / $b,
            '%' => (int)$a % (int)$b,
            '=', 'is' => $a == $b, '!=', 'is not' => $a != $b,
            '<' => $a < $b, '>' => $a > $b, '<=' => $a <= $b, '>=' => $a >= $b,
        };
    }
}
