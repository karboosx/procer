<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Procer;
use PHPUnit\Framework\TestCase;

class ParserRegressionTest extends TestCase
{
    public function testParserRejectsUnterminatedStringInScript(): void
    {
        self::expectException(ParserException::class);
        self::expectExceptionMessage('Unterminated string');

        (new Procer())->run('let a be "unterminated.');
    }

    public function testParserRejectsUnterminatedStringInExpression(): void
    {
        self::expectException(ParserException::class);
        self::expectExceptionMessage('Unterminated string');

        (new Procer())->runExpression('"unterminated');
    }

    public function testRunExpressionRejectsTrailingLiteral(): void
    {
        self::expectException(ParserException::class);
        self::expectExceptionMessage('Expected end of input');

        (new Procer())->runExpression('1 2');
    }

    public function testRunExpressionRejectsTrailingStatement(): void
    {
        self::expectException(ParserException::class);
        self::expectExceptionMessage('Expected end of input');

        (new Procer())->runExpression('1. let a be 2.');
    }

    public function testSubtractionDoesNotRequireWhitespace(): void
    {
        $context = (new Procer())->run('let a be 5-3. let b be 10-2*3. let c be (10-2)*(3-1).');

        self::assertSame(2, $context->get('a'));
        self::assertSame(4, $context->get('b'));
        self::assertSame(16, $context->get('c'));
    }

    public function testUnaryNegativeNumbers(): void
    {
        $context = (new Procer())->run('let a be -5. let b be -1.5 + 2. let c be 5--3.');

        self::assertSame(-5, $context->get('a'));
        self::assertSame(0.5, $context->get('b'));
        self::assertSame(8, $context->get('c'));
    }

    public function testUnaryNegativeVariablesAndParentheses(): void
    {
        $context = (new Procer())->run('let x be 4. let a be -x. let b be -(x + 1) * 2.');

        self::assertSame(-4, $context->get('a'));
        self::assertSame(-10, $context->get('b'));
    }

    public function testBareReturnReturnsNullAndStopsMainScript(): void
    {
        $context = (new Procer())->run('return. let a be 1.');

        self::assertNull($context->getReturnValue());
        self::assertNull($context->get('a'));
        self::assertTrue($context->isFinished());
    }

    public function testReturnNothingReturnsNullAndStopsMainScript(): void
    {
        $context = (new Procer())->run('return nothing. let a be 1.');

        self::assertNull($context->getReturnValue());
        self::assertNull($context->get('a'));
        self::assertTrue($context->isFinished());
    }
}
