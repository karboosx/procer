<?php

namespace Karboosx\Procer\Parser\Node;

class BuildInValue extends AbstractNode
{
    public function __construct(
        public readonly string $value,
    )
    {
    }
}