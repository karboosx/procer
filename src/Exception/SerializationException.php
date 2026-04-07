<?php

namespace Karboosx\Procer\Exception;

class SerializationException extends ProcerException
{
    public static function unsupportedObject(string $className): self
    {
        return new self(
            "Cannot serialize object of class '{$className}'. " .
            "Implement SerializableObjectInterface (store by ID) or JsonSerializableInterface (store inline JSON)."
        );
    }

    public static function unsupportedType(string $type): self
    {
        return new self(
            "Cannot serialize value of type '{$type}'. " .
            "Only scalars (int, float, string, bool, null), arrays, stdClass, " .
            "SerializableObjectInterface, and JsonSerializableInterface are supported."
        );
    }

    public static function jsonEncodeFailed(string $context, string $reason): self
    {
        return new self("Failed to JSON-encode {$context}: {$reason}");
    }
}
