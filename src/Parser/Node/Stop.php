<?php

namespace Procer\Parser\Node;

class Stop extends AbstractNode
{
    const STOP_KEYWORD = 'stop';

    public function jsonSerialize(): array
    {
        return [
            'type' => 'Stop',
        ];
    }
}