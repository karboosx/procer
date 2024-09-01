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

    private function getParser(): Parser
    {
        return new Parser(
            new Tokenizer(),
            true
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

            // Object function call with reversed order
//            ['func on obj.'],
            ['func(1) on obj.'],
            ['func(1, 2) on obj.'],
            ['func(1 + 2, 2) on obj.'],
            ['func(1 + 2, func2()) on obj.'],

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

            // From Loop
            ['from 1 to 3 do let x be x+1. done'],
            ['from 1 to 3 as i do let x be i. done'],
            ['from 1 to 3 as i do let x be i. from 1 to 3 as j do let x be x+1. done done'],
            ['from 1 to 3 as i do from 1 to 3 as j do let x be i + j. done done'],
            ['from 1 to 4 as i do let product be product * i. done'],
            ['from 1 to 5 as i do let factorial be factorial * i. done'],
            ['from 1 to 5 by 10 as i do done'],
            ['from 1 to 5 by 2 as i do done'],
            ['from 1 to 5 by 2 as i do let x be i. done'],
            ['from 1 to 5 by 2 as i do let x be i. from 1 to 5 by 2 as j do let x be x+1. done done'],
            ['from 1 to 5 by 2 as i do from 1 to 5 by 2 as j do let x be i + j. done done'],
            ['from 1 to 5 by 2 as i do let product be product * i. done'],
            ['from 1 to 5 by 2 as i do let factorial be factorial * i. done'],

            // While
            ['let x be 0. while x < 3 do let x be x + 1. done'],
            ['let x be 0. while x < 3 do let x be x + 1. while x < 5 do let x be x + 1. done done'],
            ['let x be 0. while x < 3 do let x be x + 1. if not do let x be 10. done done'],
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
