<?php

namespace Karboosx\Procer\Serializer;

class LazyObject implements SerializableObjectInterface
{
    private bool $isDeserialized = false;
    private mixed $realObject;

    public function __construct(
        private readonly LazyDeserializer $deserializer,
        private readonly string           $objectId
    )
    {
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (!$this->isDeserialized) {
            $this->realObject = $this->deserializer->readDeserializeObject($this->objectId);
            $this->isDeserialized = true;
        }

        return $this->realObject->$name(...$arguments);
    }

    public function __get(string $name): mixed
    {
        if (!$this->isDeserialized) {
            $this->realObject = $this->deserializer->readDeserializeObject($this->objectId);
            $this->isDeserialized = true;
        }

        return $this->realObject->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        if (!$this->isDeserialized) {
            $this->realObject = $this->deserializer->readDeserializeObject($this->objectId);
            $this->isDeserialized = true;
        }

        $this->realObject->$name = $value;
    }

    public function getSerializeId(): string
    {
        return $this->objectId;
    }
}