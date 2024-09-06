<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class FunctionCall extends AbstractNode
{
    public TokenValue $functionName;
    public array $arguments = [];
}