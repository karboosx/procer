<?php

namespace Procer\Parser\Node;

use Procer\Parser\TokenValue;

class FunctionCall extends AbstractNode
{
    public TokenValue $functionName;
    public array $arguments = [];


    public function jsonSerialize(): array
    {
        return [
            'type' => 'FunctionCall',
            'functionName' => $this->functionName,
            'arguments' => $this->arguments,
        ];
    }
}