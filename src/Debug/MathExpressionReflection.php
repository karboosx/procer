<?php

namespace Karboosx\Procer\Debug;

use Karboosx\Procer\Parser\Node\AbstractNode;
use Karboosx\Procer\Parser\Node\FunctionCall;
use Karboosx\Procer\Parser\Node\MathExpression;
use Karboosx\Procer\Parser\Node\ObjectFunctionCall;
use Karboosx\Procer\Parser\Node\Reference;

class MathExpressionReflection extends ProcerReflection
{
    public function __construct(
        private MathExpression $expression
    )
    {
    }

    public function getExpression(): MathExpression
    {
        return $this->expression;
    }

    public function getVariables(): array
    {
        $variables = [];

        $this->traverse($this->expression, function (AbstractNode $node) use (&$variables) {
            if ($node instanceof Reference) {
                $variables[] = $node->value;
            }
        });


        return array_unique($variables);
    }

    public function getFunctions(): array
    {
        $functions = [];

        $this->traverse($this->expression, function (AbstractNode $node) use (&$functions) {
            if ($node instanceof FunctionCall || $node instanceof ObjectFunctionCall) {
                $functions[] = $node->functionName->value;
            }
        });

        return array_unique($functions);
    }
}