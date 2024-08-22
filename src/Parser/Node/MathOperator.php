<?php

namespace Procer\Parser\Node;

use Procer\Parser\TokenValue;

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

    public function jsonSerialize(): array
    {
        return [
            'type' => 'MathOperator',
            'operator' => $this->operator,
            'left' => $this->left,
            'right' => $this->right,
        ];
    }
}