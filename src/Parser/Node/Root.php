<?php

namespace Procer\Parser\Node;

class Root extends AbstractNode
{
    public array $statements = [];

    public function jsonSerialize(): array
    {
        return [
            'type' => 'Root',
            'statements' => $this->statements,
        ];
    }
}