<?php

namespace Procer\Parser\Node;

use Procer\Parser\TokenValue;

class FunctionCall extends AbstractNode
{
    public TokenValue $functionName;
    public array $arguments = [];
}