<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Debug\MathExpressionReflection;
use Karboosx\Procer\Parser\Parser;
use PHPUnit\Framework\TestCase;

class ReflectionTest extends TestCase
{
    /**
     * @dataProvider mathReflectionVariablesProvider
     */
    public function testMathReflectionVariables(string $expression, array $variables)
    {
        $parser = Parser::default();

        $node = $parser->parseExpression($expression);

        $reflection = new MathExpressionReflection($node);

        $variablesFromNode = $reflection->getVariables();

        $this->assertEquals($variables, $variablesFromNode);
    }

    /**
     * @dataProvider mathReflectionFunctionsProvider
     */
    public function testMathReflectionFunctions(string $expression, array $functions)
    {
        $parser = Parser::default();

        $node = $parser->parseExpression($expression);

        $reflection = new MathExpressionReflection($node);

        $functionsFromNode = $reflection->getFunctions();

        $this->assertEquals($functions, $functionsFromNode);
    }

    public function mathReflectionVariablesProvider(): array
    {
        return [
            ['1 + 2', []],
            ['a + 2', ['a']],
            ['a + b', ['a', 'b']],
            ['a + b + c', ['a', 'b', 'c']],
            ['a + b + c + d', ['a', 'b', 'c', 'd']],
        ];
    }

    public function mathReflectionFunctionsProvider(): array
    {
        return [
            ['1 + 2', []],
            ['a + 2', []],
            ['a + b', []],
            ['a + b + c', []],
            ['a + b + c + d', []],
            ['a + b + c + d + sum(a, b, c)', ['sum']],
            ['a + b + c + d + sum(a, b, c) + avg(a, b, c)', ['sum', 'avg']],
            ['a + b + c + d + sum(a, b, c) + avg(a, b, c) + sum(a, b, c)', ['sum', 'avg']],
        ];
    }
}
