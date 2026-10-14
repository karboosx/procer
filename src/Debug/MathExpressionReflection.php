<?php

namespace Karboosx\Procer\Debug;

use Karboosx\Procer\Parser\Node\AbstractNode;
use Karboosx\Procer\Parser\Node\FunctionCall;
use Karboosx\Procer\Parser\Node\MathExpression;
use Karboosx\Procer\Parser\Node\ObjectFunctionCall;
use Karboosx\Procer\Parser\Node\Reference;
use Karboosx\Procer\Parser\Node\Exists;
use Karboosx\Procer\Parser\Node\OfAccess;

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
            } elseif ($node instanceof Exists) {
                $variables[] = $node->variable;
            } elseif ($node instanceof ObjectFunctionCall) {
                $variables[] = $node->objectName->value;
            } elseif ($node instanceof OfAccess) {
                $variables[] = $node->pathParts[count($node->pathParts) - 1]->value;
            }
        });


        return array_values(array_unique($variables));
    }

    public function getFunctions(): array
    {
        $functions = [];

        $this->traverse($this->expression, function (AbstractNode $node) use (&$functions) {
            if ($node instanceof FunctionCall || $node instanceof ObjectFunctionCall) {
                $functions[] = $node->functionName->value;
            }
        });

        return array_values(array_unique($functions));
    }
}
