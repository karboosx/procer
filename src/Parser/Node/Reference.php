<?php

namespace Procer\Parser\Node;

class Reference extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'Reference',
            'value' => $this->value,
        ];
    }
}