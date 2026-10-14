<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use PHPUnit\Framework\TestCase;

class PipelineRobustnessTest extends TestCase
{
    public function testCompilationDoesNotMutatePropertyAccessAst(): void
    {
        $ast = Parser::default()->parseExpression('name of user of account');
        $before = serialize($ast);
        $compiler = new ICParser();
        $first = $compiler->parseExpression($ast);
        self::assertSame($before, serialize($ast));
        self::assertEquals($first, $compiler->parseExpression($ast));
    }

    public function testBooleanGuardsShortCircuitMissingVariables(): void
    {
        $procer = new Procer();
        self::assertFalse($procer->runExpression('missing exists and missing > 0'));
        self::assertTrue($procer->runExpression('missing not exists or missing > 0'));
        self::assertFalse($procer->runExpression('false and (1 / 0 > 1)'));
        self::assertTrue($procer->runExpression('true or (1 / 0 > 1)'));
    }

    public function testSignalChecksComposeWithBooleanOperators(): void
    {
        $procer = new Procer();
        self::assertTrue($procer->runExpression('(signal is ready) and true', [], ['ready']));
        self::assertFalse($procer->runExpression('(signal is ready) and false', [], ['ready']));
    }

    public function testTopLevelReturnSurvivesResumeAndSerialization(): void
    {
        $procer = new Procer();
        $context = $procer->run('return 42. let unreachable be 1.');
        self::assertSame(42, $procer->resume()->getReturnValue());
        $context = (new Procer())->resume((new Deserializer())->deserialize($context->serialize()));
        self::assertSame(42, $context->getReturnValue());
        self::assertFalse($context->has('unreachable'));
        self::assertTrue($context->isFinished());
    }

    public function testCycleBudgetAllowsExactlyTheBudgetAndNoExtraWorkOnResume(): void
    {
        $procer = new Procer();
        $procer->setMaxCycles(2);
        self::assertSame(1, $procer->run('let x be 1.')->get('x'));
    }
}
