<?php

namespace Karboosx\Procer\Parser\Node;

class WhileLoop extends AbstractNode
{
    const WHILE_KEYWORD = 'while';
    const UNTIL_KEYWORD = 'until';
    const DO_KEYWORD = 'do';
    const STOPPING_KEYWORD = 'stopping';

    public MathExpression $expression;

    public array $statements = [];

    public bool $stopping = false;
}