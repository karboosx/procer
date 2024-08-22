<?php

namespace Procer\Parser\Node;

class StringNode extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'StringNode',
            'value' => $this->value,
        ];
    }
}