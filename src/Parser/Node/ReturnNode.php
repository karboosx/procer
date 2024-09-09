<?php

namespace Karboosx\Procer\Parser\Node;

class ReturnNode extends AbstractNode
{
    const RETURN_KEYWORD = 'return';
    public ?MathExpression $expression = null;
}