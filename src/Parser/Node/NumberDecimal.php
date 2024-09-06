<?php

namespace Karboosx\Procer\Parser\Node;

class NumberDecimal extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }
}