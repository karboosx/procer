<?php

namespace Procer\Parser\Node;

class MathExpression extends AbstractNode
{
    public AbstractNode $node;

    public function jsonSerialize(): array
    {
        return [
            'type' => 'MathExpression',
            'node' => $this->node,
        ];
    }
}