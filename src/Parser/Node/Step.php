<?php

namespace Karboosx\Procer\Parser\Node;

class Step extends AbstractNode
{
    public string $name;
    public array $statements = [];
    public ?string $failureVariable = null;
    public array $handler = [];
}
