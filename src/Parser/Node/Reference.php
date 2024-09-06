<?php

namespace Karboosx\Procer\Parser\Node;

class Reference extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }
}