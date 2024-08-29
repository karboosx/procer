<?php

namespace Procer\Parser\Node;

class Number extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }
}