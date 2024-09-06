<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class ForEachLoop extends AbstractNode
{
    const FOR_KEYWORD = 'for';
    const IN_KEYWORD = 'in';
    const DO_KEYWORD = 'do';
    const EACH_KEYWORD = 'each';

    public MathExpression $arrayExpression;
    public TokenValue $asVariable;

    public array $statements = [];
}