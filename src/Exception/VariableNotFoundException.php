<?php

namespace Karboosx\Procer\Exception;

use Karboosx\Procer\IC\TokenInfo;

class VariableNotFoundException extends RunnerException
{
    public function __construct(
        private readonly string $variableName,
        ?TokenInfo $tokenInfo = null
    ) {
        parent::__construct(
            "Variable '{$variableName}' is not defined",
            $tokenInfo
        );
    }

    public function getVariableName(): string
    {
        return $this->variableName;
    }
}
