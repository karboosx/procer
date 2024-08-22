<?php

namespace Procer\Parser\Node;

use JsonSerializable;
use Procer\Parser\Token;

abstract class AbstractNode implements JsonSerializable
{
    public ?Token $token = null;

    abstract public function jsonSerialize(): array;
}
