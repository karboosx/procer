<?php

namespace Karboosx\Procer\Exception;

class DeserializationException extends ProcerException
{
    public static function invalidJson(string $reason): self
    {
        return new self("Cannot deserialize: invalid or corrupt JSON — {$reason}");
    }

    public static function versionMismatch(int $expected, int $actual): self
    {
        return new self(
            "Cannot deserialize: serialized data uses format version {$actual}, " .
            "but this version of Procer only supports version {$expected}. " .
            "Re-serialize the process with the current version."
        );
    }

    public static function unknownObjectId(string $objectId): self
    {
        return new self(
            "Cannot deserialize object with ID '{$objectId}': no registered provider supports it. " .
            "Pass a DeserializeObjectProviderInterface to the Deserializer constructor."
        );
    }

    public static function classNotFound(string $className): self
    {
        return new self(
            "Cannot deserialize JSON object: class '{$className}' does not exist. " .
            "Make sure the class is autoloaded before deserializing."
        );
    }

    public static function classNotJsonSerializable(string $className): self
    {
        return new self(
            "Cannot deserialize JSON object: class '{$className}' does not implement JsonSerializableInterface."
        );
    }

    public static function corruptStdClass(): self
    {
        return new self("Cannot deserialize stdClass: data is corrupt (expected [key, value] pairs).");
    }

    public static function unknownValueType(string $raw): self
    {
        return new self(
            "Cannot deserialize value: unknown type prefix in '{$raw}'. " .
            "The serialized data may be corrupt or from an incompatible version."
        );
    }

    public static function missingField(string $field): self
    {
        return new self(
            "Cannot deserialize: required field '{$field}' is missing from the serialized data."
        );
    }
}
