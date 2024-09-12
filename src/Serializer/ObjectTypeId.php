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
        $parts = explode(':', $string);
        return new ObjectTypeId($parts[0], (int) $parts[1]);
    }
}