<?php

namespace Karboosx\Procer\Runner;

use Karboosx\Procer\IC\IC;

class Process
{
    public array $scopes = [];

    public IC $ic;

    public int $currentInstructionIndex = 0;

    public function __construct()
    {
        $this->scopes = [new Scope()];
    }
}