<?php

namespace Karboosx\Procer\Parser\Node;

class RecordNode extends AbstractNode
{
    /** @var string[] */
    public array $names = [];
    /** @var MathExpression[] */
    public array $values = [];
}
