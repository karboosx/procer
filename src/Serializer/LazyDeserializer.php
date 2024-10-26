<?php

namespace Karboosx\Procer\Serializer;

class LazyDeserializer extends Deserializer
{
    protected function deserializeObject(string $objectId): SerializableObjectInterface
    {
        return new LazyObject($this, $objectId);
    }

    public function readDeserializeObject(string $serialized): mixed
    {
        return parent::deserializeObject($serialized);
    }
}