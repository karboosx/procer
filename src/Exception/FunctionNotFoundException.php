<?php

namespace Procer\Exception;

use Procer\IC\TokenInfo;

class FunctionNotFoundException extends RunnerException
{
    private string $functionName;

    public function __construct(string $message = "", string $functionName = "", ?TokenInfo $tokenInfo = null)
    {
        $this->functionName = $functionName;

        parent::__construct($message, $tokenInfo);
    }

    /** @noinspection PhpUnused */
    public function getFunctionName(): string
    {
        return $this->functionName;
    }
}