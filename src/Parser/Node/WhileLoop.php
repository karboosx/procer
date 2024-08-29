<?php

namespace Procer\Parser\Node;

class WhileLoop extends AbstractNode
{
    const WHILE_KEYWORD = 'while';
    const UNTIL_KEYWORD = 'until';
    const DO_KEYWORD = 'do';
    const DONE_KEYWORD = 'done';

    public MathExpression $expression;

    public array $statements = [];
}