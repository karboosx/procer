<?php

namespace Karboosx\Procer\Parser\Node;

use JsonSerializable;
use Karboosx\Procer\Parser\Token;

abstract class AbstractNode
{
    public ?Token $token = null;
}
