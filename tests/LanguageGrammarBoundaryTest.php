<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class LanguageGrammarBoundaryTest extends TestCase
{
    /** @dataProvider multilineHeaders */
    public function testIndentationBodyMustStartAfterTheEndOfItsHeader(string $header): void
    {
        self::assertCount(1, Parser::default()->parse($header . "\n    nothing.")->statements);
        self::assertCount(1, (new Parser(new Tokenizer(), true))->parse($header . ' nothing. done')->statements);
        $this->expectException(ParserException::class);
        Parser::default()->parse($header . ' nothing.');
    }

    public static function multilineHeaders(): array
    {
        return [
            ["if (\n    true) do"], ["while (\n    false) do"], ["until (\n    true) do"],
            ["from (1)\n    to (2) do"], ["for each item\n    in items do"], ["procedure f(\n    x) do"],
        ];
    }

    /** @dataProvider malformedArgumentLists */
    public function testMalformedArgumentsAreRejectedInEveryCallSpelling(string $source): void
    {
        $this->expectException(ParserException::class);
        (new Parser(new Tokenizer(), true))->parse($source);
    }

    public static function malformedArgumentLists(): iterable
    {
        foreach (['f(%s).', 'on obj f(%s).', 'on obj do f(%s).', 'on obj run f(%s).', 'f(%s) on obj.', 'let result be outer(1, f(%s), 3).'] as $template) {
            foreach (['1 2', '1,', ',1', '1,,2', '1;2', '(1 + 2),', '1 +, 2'] as $arguments) {
                $source = sprintf($template, $arguments);
                yield $source => [$source];
            }
        }
    }

    public function testKeywordPrefixesAndIdentifierCaseDoNotChangeNames(): void
    {
        foreach (['ifx', 'done2', 'origin', 'notable', 'onward', 'signalReady', 'existence', 'trueValue', 'True', 'NULL', 'returning', 'for_each'] as $name) {
            $source = "let $name be 7.\nprocedure read($name) do\n    return $name + 1.\nreturn read($name) + $name.";
            self::assertSame(15, (new Procer())->run($source)->getReturnValue(), $source);
        }
        self::assertSame(12, (new Procer())->run('let item be 1. let Item be 2. return item * 10 + Item.')->getReturnValue());
    }

    public function testProceduresReadTheirOwnParametersAndGlobalsRatherThanCallerLocals(): void
    {
        $source = <<<'CODE'
let value be 100.
procedure outer(value) do
    let value be value + 1.
    let privateValue be 7.
    return inner() + value.
procedure inner do
    if privateValue exists do
        return -1000.
    return value.
let result be outer(4).
return result + value.
CODE;
        $context = (new Procer())->run($source);
        self::assertSame(205, $context->getReturnValue());
        self::assertFalse($context->has('privateValue'));
    }

    public function testNonTailRecursionWithTwoCallsPreservesBothResults(): void
    {
        $source = <<<'CODE'
procedure fibonacci(n) do
    if n < 2 do
        return n.
    return fibonacci(n - 1) + fibonacci(n - 2).
return fibonacci(8).
CODE;
        $procer = new Procer();
        $procer->setMaxCycles(5000);
        $context = $procer->run($source);
        self::assertSame(21, $context->getReturnValue());
        self::assertCount(1, $context->getProcess()->scopes);
        self::assertSame([], $context->getProcess()->scopes[0]->stack);
    }
}
