<?php

namespace Karboosx\Procer\Serializer;

use Karboosx\Procer\Exception\DeserializationException;

readonly class ObjectTypeId
{
    public function __construct(
        private string $type,
        private int    $id
    )
    {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->type . ':' . $this->id;
    }

    static public function fromString(string $string): ObjectTypeId
    {
        $parts = explode(':', $string, 2);
        if (count($parts) !== 2 || $parts[0] === '' || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $parts[1]) || filter_var($parts[1], FILTER_VALIDATE_INT) === false) {
            throw DeserializationException::invalidJson('object ID must have the form type:integer');
        }
        return new ObjectTypeId($parts[0], (int)$parts[1]);
    }

    static public function isTypeOf(string $string, string $type): bool
    {
        return self::fromString($string)->getType() === $type;
    }
}
