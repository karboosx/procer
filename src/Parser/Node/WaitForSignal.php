<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class WaitForSignal extends AbstractNode
{
    const WAIT_KEYWORD = 'wait';
    const FOR_KEYWORD = 'for';
    const SIGNAL_KEYWORD = 'signal';
    public TokenValue $signalName;
}