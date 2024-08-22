<?php

namespace Procer\Parser\Node;

class NumberDecimal extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'NumberDecimal',
            'value' => $this->value,
        ];
    }
}