<?php

namespace Procer\Tests;

use Procer\Exception\ParserException;
use Procer\IC\IC;
use Procer\IC\ICParser;
use Procer\Parser\Node\AbstractNode;
use Procer\Parser\Node\Root;
use Procer\Parser\Parser;
use Procer\Parser\Tokenizer;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    /**
     * @dataProvider provideExpressions
     */
    public function testParserExpressions($expression): void
    {
        $ast = $this->parse($expression);
        $json = json_encode($ast);

        self::assertNotEquals('{"type":"Root","statements":[]}', $json);
        self::assertInstanceOf(AbstractNode::class, $ast);
        self::assertJson($json);
    }

    /**
     * @dataProvider provideExpressions
     */
    public function testIcParserExpressions($expression): void
    {
        $ast = $this->parse($expression);
        $icParser = new ICParser();
        $ic = $icParser->parse($ast);

        self::assertInstanceOf(IC::class, $ic);
        self::assertGreaterThan(0, count($ic->getInstructions()));
    }

    public function testLetExpression(): void
    {
        $code = 'let a be 1.';
        $rootNode = $this->parse($code);
        $json = json_encode($rootNode);
        static::assertSame('{"type":"Root","statements":[{"type":"Let","variable":{"token":{"type":"IDENTIFIER","value":"a","line":1,"linePosition":4},"value":"a"},"expression":{"type":"MathExpression","node":{"type":"Number","value":"1"}}}]}', $json);
    }

    public function testExpressionPrecedence(): void
    {
        $code = 'let a be 1 + 1 < 2 + 2.';
        $rootNode = $this->parse($code);
        $json = json_encode($rootNode);
        static::assertSame('{"type":"Root","statements":[{"type":"Let","variable":{"token":{"type":"IDENTIFIER","value":"a","line":1,"linePosition":4},"value":"a"},"expression":{"type":"MathExpression","node":{"type":"MathOperator","operator":{"token":{"type":"LESS_THEN","value":"<","line":1,"linePosition":15},"value":"<"},"left":{"type":"MathOperator","operator":{"token":{"type":"PLUS","value":"+","line":1,"linePosition":11},"value":"+"},"left":{"type":"Number","value":"1"},"right":{"type":"Number","value":"1"}},"right":{"type":"MathOperator","operator":{"token":{"type":"PLUS","value":"+","line":1,"linePosition":19},"value":"+"},"left":{"type":"Number","value":"2"},"right":{"type":"Number","value":"2"}}}}}]}', $json);
    }

    public function testFunctionCallExpression(): void
    {
        $code = 'func().';
        $rootNode = $this->parse($code);
        $json = json_encode($rootNode);
        static::assertSame('{"type":"Root","statements":[{"type":"FunctionCall","functionName":{"token":{"type":"IDENTIFIER","value":"func","line":1,"linePosition":0},"value":"func"},"arguments":[]}]}', $json);
    }

    public function testObjectFunctionCallExpression(): void
    {
        $code = 'on obj func().';
        $rootNode = $this->parse($code);
        $json = json_encode($rootNode);
        static::assertSame('{"type":"Root","statements":[{"type":"ObjectFunctionCall","objectName":{"token":{"type":"IDENTIFIER","value":"obj","line":1,"linePosition":3},"value":"obj"},"functionName":{"token":{"type":"IDENTIFIER","value":"func","line":1,"linePosition":7},"value":"func"},"arguments":[]}]}', $json);
    }

    public function testIf()
    {
        $code = 'if 1 > 2 do let x be 1. or 2 > 3 do let x be 2. if not do let x be 3. done';
        $rootNode = $this->parse($code);
        $json = json_encode($rootNode);
        static::assertSame('{"type":"Root","statements":[{"type":"IfNode","expression":{"type":"MathExpression","node":{"type":"MathOperator","operator":{"token":{"type":"MORE_THEN","value":">","line":1,"linePosition":5},"value":">"},"left":{"type":"Number","value":"1"},"right":{"type":"Number","value":"2"}}},"statements":[{"type":"Let","variable":{"token":{"type":"IDENTIFIER","value":"x","line":1,"linePosition":16},"value":"x"},"expression":{"type":"MathExpression","node":{"type":"Number","value":"1"}}}],"or":{"type":"IfNode","expression":{"type":"MathExpression","node":{"type":"MathOperator","operator":{"token":{"type":"MORE_THEN","value":">","line":1,"linePosition":29},"value":">"},"left":{"type":"Number","value":"2"},"right":{"type":"Number","value":"3"}}},"statements":[{"type":"Let","variable":{"token":{"type":"IDENTIFIER","value":"x","line":1,"linePosition":40},"value":"x"},"expression":{"type":"MathExpression","node":{"type":"Number","value":"2"}}}],"or":null,"not":{"type":"IfNode","expression":null,"statements":[{"type":"Let","variable":{"token":{"type":"IDENTIFIER","value":"x","line":1,"linePosition":62},"value":"x"},"expression":{"type":"MathExpression","node":{"type":"Number","value":"3"}}}],"or":null,"not":null}},"not":null}]}', $json);
    }

    private function getParser(): Parser
    {
        return new Parser(
            new Tokenizer()
        );
    }

    public function provideExpressions(): array
    {
        return [
            // Let
            ['let a be 1.'],
            ['let a be 1 + 2.'],
            ['let a be 1. let b be 2.'],

            // Let with bool expression
            ['let a be 1 > 2.'],
            ['let a be 1 < 2.'],
            ['let a be 1 >= 2.'],
            ['let a be 1 <= 2.'],
            ['let a be 1 = 2.'],
            ['let a be 1 != 2.'],

            // Let with two bool expression
            ['let a be 1 > 2 < 3.'],
            ['let a be 1 < 2 > 3.'],
            ['let a be 1 >= 2 <= 3.'],
            ['let a be 1 <= 2 >= 3.'],
            ['let a be 1 = 2 != 3.'],
            ['let a be 1 != 2 = 3.'],

            // Precedence
            ['let a be 1 + 1 < 2 + 2.'],

            // Function call
            ['func().'],
            ['func(1).'],
            ['func(1, 2).'],
            ['func(1 + 2, 2).'],
            ['func(1 + 2, func2()).'],

            // Object function call
            ['on obj func().'],
            ['on obj func(1).'],
            ['on obj func(1, 2).'],
            ['on obj func(1 + 2, 2).'],
            ['on obj func(1 + 2, func2()).'],

            // Object function call without parenthesis
            ['on obj func.'],

            // Object function call with optional verb
            ['on obj to func().'],
            ['on obj on func(1).'],
            ['on obj run func(1, 2).'],
            ['on obj do_it func(1 + 2, 2).'],
            ['on obj please_do func(1 + 2, func2()).'],

            // Double object function call with optional verb
            ['on obj to func().'. 'on obj to func().'],
            ['on obj on func(1).'. 'on obj on func(1).'],
            ['on obj run func(1, 2).'. 'on obj run func(1, 2).'],
            ['on obj do_it func(1 + 2, 2).'. 'on obj do_it func(1 + 2, 2).'],
            ['on obj please_do func(1 + 2, func2()).'. 'on obj please_do func(1 + 2, func2()).'],

            // Object function call with optional verb without parenthesis
            ['on obj to func.'],
            ['on obj on func.'],
            ['on obj run func.'],
            ['on obj do_it func.'],
            ['on obj please_do func.'],

            // Let on object
            ['let X be on obj func.'],
            ['let X be on obj func().'],
            ['let X be on obj func(1).'],
            ['let X be on obj on func(1, 2).'],
            ['let X be on obj on func.'],
            ['let X be on obj func(1 + 2, 2).'],
            ['let X be on obj func(1 + 2, func2()).'],
            ['let X be on obj func(1 + 2, on obj2 func2()).'],

            // Double Let on object
            ['let X be on obj func.'.'let X be on obj func.'],
            ['let X be on obj func().'.'let X be on obj func().'],
            ['let X be on obj func(1).'.'let X be on obj func(1).'],
            ['let X be on obj on func(1, 2).'.'let X be on obj on func(1, 2).'],
            ['let X be on obj on func.'.'let X be on obj on func.'],
            ['let X be on obj func(1 + 2, 2).'.'let X be on obj func(1 + 2, 2).'],
            ['let X be on obj func(1 + 2, func2()).'.'let X be on obj func(1 + 2, func2()).'],
            ['let X be on obj func(1 + 2, on obj2 func2()). '.'let X be on obj func(1 + 2, on obj2 func2()).'],

            // If
            ['if 1 > 2 do done'],
            ['if 1 > 2 do let x be 1. done'],
            ['if 1 > 2 do let x be 1. or 2 > 3 do let x be 2. done'],
            ['if 1 > 2 do let x be 1. or 2 > 3 do let x be 2. if not do let x be 3. done'],

            ['if signal is red do stop. done'],
            ['if signal is red do stop. or signal is green do go(). done'],
            ['if signal is red do stop. or signal is green do go(). if not do let x be 3. done'],
            // Stop
            ['stop.'],

        ];
    }

    /**
     * @throws ParserException
     */
    private function parse($expression): Root
    {
        $parser = $this->getParser();
        return $parser->parse($expression);
    }
}
