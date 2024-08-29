<?php

namespace Procer\Parser\Node;

use JsonSerializable;
use Procer\Parser\Token;

abstract class AbstractNode
{
    public ?Token $token = null;
}
