<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class ParserRobustnessTest extends TestCase
{
    /** @dataProvider invalidScripts */
    public function testInvalidScriptsAreRejected(string $source, bool $done = true): void
    {
        $this->expectException(ParserException::class);
        (new Parser(new Tokenizer(), $done))->parse($source);
    }

    public static function invalidScripts(): iterable
    {
        foreach ([
            'f(1 2).', 'f(1,).', 'f(,1).', 'f(1,,2).',
            'on obj f(1 2).', 'on obj do f(1,).',
            'procedure f(a b) do done', 'procedure f(a,) do done',
            'procedure f(a, a) do done', 'procedure f do done procedure f do done',
            'let x be .', 'let x 1.', 'let x be 1', 'let x be (1 + 2.',
            'if true do', 'while true do', 'from 1 to 2 do', 'for each x in xs do',
            'procedure f do', 'wait for signal.', 'wait for signal a, .',
            'done.', 'or.', 'if not do nothing. done',
            'if true do procedure f do done done', 'return 1 2.',
            'let x be 1 @.', "let x be 1.\0", 'let x be "unterminated',
        ] as $source) {
            yield $source => [$source];
        }
        foreach ([
            "if true do\nlet x be 1.", "if true do", "while true do\n// empty",
            "if true do\n    nothing.\n  nothing.",
            "if true do\n    nothing.\n        nothing.",
            "nothing.\n    nothing.",
            "if true do nothing.",
        ] as $source) {
            yield 'indent: ' . $source => [$source, false];
        }
    }

    /** @dataProvider unaryExpressions */
    public function testUnarySigns(string $source, mixed $expected): void
    {
        self::assertSame($expected, (new Procer())->runExpression($source));
    }

    public static function unaryExpressions(): array
    {
        return [['--5', 5], ['---5', -5], ['----1.5', 1.5], ['5---3', 2], ['-(-(-2))', -2]];
    }

    public function testOuterElseDoesNotAttachToInnerIf(): void
    {
        $source = "let x be 0.\nif false do\n    if true do\n        let x be 1.\nif not do\n    let x be 2.";
        self::assertSame(2, (new Procer())->run($source)->get('x'));
    }

    public function testOuterOrDoesNotAttachToInnerIf(): void
    {
        $source = "let x be 0.\nif false do\n    if true do\n        let x be 1.\nor true do\n    let x be 2.";
        self::assertSame(2, (new Procer())->run($source)->get('x'));
    }

    public function testFirstIndentedLineAndUnequalStatementLengthsDoNotChangeBlocks(): void
    {
        $source = "  let x be 0.\n  if true do\n      let x be 1. let x be x + 1.\n      let x be x + 1.\n  let x be x + 10.";
        self::assertSame(13, (new Procer())->run($source)->get('x'));
    }

    public function testEscapedQuotesAndBackslashes(): void
    {
        self::assertSame('say "hi" \\ path\\n', (new Procer())->runExpression('"say \\"hi\\" \\\\ path\\n"'));
    }

    public function testParserCanBeReusedAfterAnError(): void
    {
        $parser = Parser::default();
        foreach (['f(', '"unterminated', 'if true do'] as $source) {
            try {
                $parser->parse($source);
                self::fail('Expected rejection: ' . $source);
            } catch (ParserException) {
                self::assertEquals(Parser::default()->parse('let x be 2.'), $parser->parse('let x be 2.'));
            }
        }
    }
}
