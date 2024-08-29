<?php

namespace Procer\Parser\Node;

use Procer\Parser\TokenValue;

class ObjectFunctionCall extends AbstractNode
{
    const ON_KEYWORD = 'on';
    public TokenValue $objectName;
    public TokenValue $functionName;
    public array $arguments = [];
}