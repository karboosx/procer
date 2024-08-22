<?php

namespace Procer\Serializer;

class ObjectTypeId
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
}