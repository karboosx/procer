<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Parser\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function testIndentIsConstantAcrossEachLineIncludingFirstLine(): void
    {
        $tokens = (new Tokenizer())->tokenize("    let a be 1.\n\tlet b be 2.");
        self::assertSame([4, 4, 4, 4, 4, 1, 1, 1, 1, 1], array_column($tokens, 'indent'));
        self::assertSame([4, 8, 10, 13, 14, 1, 5, 7, 10, 11], array_column($tokens, 'linePosition'));
    }

    public function testEveryNewlineStyleAndCommentsHaveCorrectLocations(): void
    {
        foreach (["\n", "\r\n", "\r"] as $eol) {
            $tokens = (new Tokenizer())->tokenize('// comment' . $eol . '  a >= 12.' . $eol . 'b');
            self::assertSame([2, 2, 2, 2, 3], array_column($tokens, 'line'));
            self::assertSame([2, 4, 7, 9, 0], array_column($tokens, 'linePosition'));
            self::assertSame([2, 2, 2, 2, 0], array_column($tokens, 'indent'));
        }
    }

    public function testMultilineStringTracksOpeningAndFollowingTokenPositions(): void
    {
        $tokens = (new Tokenizer())->tokenize("  \"first\nsecond\".\n@");
        self::assertSame([1, 2, 3], array_column($tokens, 'line'));
        self::assertSame([2, 7, 0], array_column($tokens, 'linePosition'));
    }

    public function testTokenizerCanBeReused(): void
    {
        $tokenizer = new Tokenizer();
        $tokenizer->tokenize("\n\n  let x be 1.");
        self::assertEquals((new Tokenizer())->tokenize('1-2'), $tokenizer->tokenize('1-2'));
    }
}
