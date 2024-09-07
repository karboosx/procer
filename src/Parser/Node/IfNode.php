<?php

namespace Karboosx\Procer\Parser\Node;

class IfNode extends AbstractNode
{
    const IS_OPERATOR = 'is';
    const IF_KEYWORD = 'if';
    const DO_KEYWORD = 'do';
    const OR_KEYWORD = 'or';
    const AND_KEYWORD = 'and';
    const NOT_KEYWORD = 'not';
    const IS_NOT_OPERATOR = 'is_not';

    public ?MathExpression $expression = null;
    public array $statements = [];

    public ?IfNode $or = null;
    public ?IfNode $not = null;
}