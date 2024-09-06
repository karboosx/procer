<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class MathOperator extends AbstractNode
{
    public AbstractNode $left;
    public TokenValue $operator;
    public AbstractNode $right;

    public function __construct(
        TokenValue $operator,
    )
    {
        $this->operator = $operator;
    }
}