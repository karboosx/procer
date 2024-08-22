<?php

namespace Procer\Exception;

use Procer\IC\TokenInfo;

class ObjectFunctionNotFoundException extends RunnerException
{
    private string $functionName;
    private string $objectClass;

    public function __construct(string $message = "", string $functionName = "", string $objectClass = "", ?TokenInfo $tokenInfo = null)
    {
        $this->functionName = $functionName;
        $this->objectClass = $objectClass;

        parent::__construct($message, $tokenInfo);
    }

    /** @noinspection PhpUnused */
    public function getFunctionName(): string
    {
        return $this->functionName;
    }

    /** @noinspection PhpUnused */
    public function getObjectClass(): string
    {
        return $this->objectClass;
    }
}