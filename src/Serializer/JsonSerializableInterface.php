<?php

namespace Karboosx\Procer\Serializer;

interface JsonSerializableInterface
{
    public function toJson(): string;
    public static function fromJson(string $json): self;
}