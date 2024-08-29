<?php

namespace Procer\Parser\Node;

class StringNode extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }
}