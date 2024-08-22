<?php

namespace Procer\Parser\Node;

class Number extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'Number',
            'value' => $this->value,
        ];
    }
}