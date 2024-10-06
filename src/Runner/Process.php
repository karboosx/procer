<?php

namespace Karboosx\Procer\Runner;

use Karboosx\Procer\IC\IC;
use Karboosx\Procer\Interrupt\InterruptType;

class Process
{
    public array $scopes = [];

    public IC $ic;

    public int $cycles = 0;

    public int $currentInstructionIndex = 0;

    public ?InterruptType $lastInterruptType = null;

    public function __construct()
    {
        $this->scopes = [new Scope()];
    }
}