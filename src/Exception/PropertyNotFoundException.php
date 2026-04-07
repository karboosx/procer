<?php

namespace Karboosx\Procer\Exception;

use Karboosx\Procer\IC\TokenInfo;

class PropertyNotFoundException extends RunnerException
{
    public function __construct(
        private readonly string $propertyName,
        private readonly string $objectClass,
        ?TokenInfo $tokenInfo = null
    ) {
        parent::__construct(
            "Property or method '{$propertyName}' not found on object of type '{$objectClass}'",
            $tokenInfo
        );
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getObjectClass(): string
    {
        return $this->objectClass;
    }
}
