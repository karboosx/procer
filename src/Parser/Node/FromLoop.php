<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class FromLoop extends AbstractNode
{
    const FROM_KEYWORD = 'from';
    const TO_KEYWORD = 'to';
    const BY_KEYWORD = 'by';
    const AS_KEYWORD = 'as';
    const DO_KEYWORD = 'do';

    public MathExpression $from;
    public MathExpression $to;
    public ?MathExpression $step = null;
    public ?TokenValue $asVariable = null;

    public array $statements = [];
}