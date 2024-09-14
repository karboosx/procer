<?php

namespace Karboosx\Procer\Parser\Node;

use Karboosx\Procer\Parser\TokenValue;

class WaitForSignal extends AbstractNode
{
    const WAIT_KEYWORD = 'wait';
    const FOR_KEYWORD = 'for';
    const ALL_KEYWORD = 'all';
    const SIGNAL_KEYWORD = 'signal';
    const SIGNALS_KEYWORD = 'signals';
    /**
     * @var TokenValue[]
     */
    public array $signalNames;
    /**
     * @var true
     */
    public bool $all = false;
}