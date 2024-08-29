<?php

namespace Procer\Serializer;

interface DeserializeObjectProviderInterface
{
    public function supports(string $objectId): bool;

    public function deserialize(string $objectId): mixed;
}