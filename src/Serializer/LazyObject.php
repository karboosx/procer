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

    public function getRealObject(): mixed
    {
        if (!$this->isDeserialized) {
            $this->realObject = $this->deserializer->readDeserializeObject($this->objectId);
            $this->isDeserialized = true;
        }

        return $this->realObject;
    }

    public function getSerializeId(): string
    {
        return $this->objectId;
    }
}