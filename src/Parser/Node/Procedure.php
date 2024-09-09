<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class Procedure extends AbstractNode
{
    const PROCEDURE_KEYWORD = 'procedure';
    const DO_KEYWORD = 'do';
    public TokenValue $procedureName;
    public array $arguments = [];
    public array $statements = [];
}