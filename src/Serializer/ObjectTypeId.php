<?php

namespace Karboosx\Procer\Serializer;

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
        return new ObjectTypeId($parts[0], (int)$parts[1]);
    }

    static public function isTypeOf(string $string, string $type): bool
    {
        return self::fromString($string)->getType() === $type;
    }
}