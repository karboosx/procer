<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class Let extends AbstractNode
{
    const LET_KEYWORD = 'let';
    const BE_KEYWORD = 'be';

    public function __construct(
        public readonly TokenValue     $variable,
        public readonly MathExpression $expression
    )
    {
    }
}