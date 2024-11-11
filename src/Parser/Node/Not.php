<?php

namespace Karboosx\Procer\Parser\Node;

class Not extends AbstractNode
{
    public function __construct(
        public readonly AbstractNode $term,
    )
    {
    }
}