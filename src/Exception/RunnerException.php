<?php

namespace Karboosx\Procer\Exception;

use Karboosx\Procer\IC\TokenInfo;

class RunnerException extends ProcerException
{
    private ?TokenInfo $tokenInfo;

    public function __construct(string $message, ?TokenInfo $tokenInfo = null)
    {
        $this->tokenInfo = $tokenInfo;
        if ($tokenInfo === null) {
            parent::__construct($message);
            return;
        }

        parent::__construct($message . ' at line ' . $tokenInfo->line . ' position ' . $tokenInfo->linePosition);
    }

    public function getTokenInfo(): ?TokenInfo
    {
        return $this->tokenInfo;
    }
}