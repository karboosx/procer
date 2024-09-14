<?php

namespace Karboosx\Procer\Parser\Node;

class OfAccess extends AbstractNode
{
    const OF_KEYWORD = 'of';
    public array $pathParts = [];
}