<?php

namespace Karboosx\Procer\Parser\Node;

class Exists extends AbstractNode
{
    const EXISTS_OPERATOR = 'exists';
    const NOT_EXISTS_OPERATOR = 'not exists';

    public function __construct(
        public readonly string $variable,
        public readonly bool $isNot = false,
    )
    {
    }
}