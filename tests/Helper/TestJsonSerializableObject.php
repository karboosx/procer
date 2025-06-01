<?php

namespace Karboosx\Procer\Tests\Helper;

use Karboosx\Procer\Serializer\JsonSerializableInterface;

class TestJsonSerializableObject implements JsonSerializableInterface {
    public string $property = 'dupa';
    public function toJson(): string
    {
        return json_encode(['property' => $this->property]);
    }

    public static function fromJson(string $json): JsonSerializableInterface
    {
        $data = json_decode($json, true);
        $instance = new self();
        $instance->property = $data['property'];
        return $instance;
    }
}