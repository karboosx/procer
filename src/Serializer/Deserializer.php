<?php

namespace Karboosx\Procer\Serializer;

use Karboosx\Procer\Exception\DeserializationException;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\IC\TokenInfo;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Runner\Scope;

class Deserializer
{
    /**
     * @var DeserializeObjectProviderInterface[]
     */
    private array $providers;

    public function __construct(DeserializeObjectProviderInterface ...$providers)
    {
        $this->providers = $providers;
    }

    const FORMAT_VERSION = 1;

    public function deserialize(string $data): Process
    {
        $json = json_decode($data, true);

        if ($json === null) {
            throw DeserializationException::invalidJson(json_last_error_msg());
        }

        if (!isset($json['v'])) {
            throw DeserializationException::missingField('v');
        }

        if ($json['v'] !== self::FORMAT_VERSION) {
            throw DeserializationException::versionMismatch(self::FORMAT_VERSION, $json['v']);
        }

        foreach (['s', 'ic', 'i', 'c'] as $field) {
            if (!isset($json[$field])) {
                throw DeserializationException::missingField($field);
            }
        }

        $scopes = $this->deserializeScopes($json['s']);
        $ic = $this->deserializeIC($json['ic']);
        $index = $this->deserializeValue($json['i']);

        $process = new Process();

        $process->scopes = $scopes;
        $process->ic = $ic;
        $process->cycles = $json['c'];
        $process->currentInstructionIndex = $index;
        $process->lastInterruptType = isset($json['l']) ? $this->deserializeInterruptType($json['l']) : null;

        return $process;
    }

    private function deserializeScopes(array $scopes): array
    {
        $output = [];
        foreach ($scopes as $scope) {
            $output[] = $this->deserializeScope($scope);
        }

        return $output;
    }

    private function deserializeScope(array $scopeData): Scope
    {
        $scope = new Scope();
        $scope->variables = $this->deserializeArray($scopeData['v']);
        $scope->stack = $this->deserializeArray($scopeData['s']);
        $scope->returnValue = $this->deserializeValue($scopeData['r']);
        $scope->returnPointer = $scopeData['p'];

        return $scope;
    }

    private function deserializeIC(array $ic): IC
    {
        $instructions = [];
        foreach ($ic['i'] as $instruction) {
            $instructions[] = $this->deserializeInstruction($instruction);
        }

        return new IC($instructions, $ic['p']);
    }

    private function deserializeInstruction(array $instruction): ICInstruction
    {
        return new ICInstruction(
            InstructionType::from($instruction['o']),
            $this->deserializeArray($instruction['a']),
            $this->deserializeTokenInfo($instruction['t'])
        );
    }

    private function deserializeTokenInfo(array|null $tokenInfo): TokenInfo|null
    {
        if ($tokenInfo === null) {
            return null;
        }

        return new TokenInfo(
            $tokenInfo['l'],
            $tokenInfo['p'],
            $tokenInfo['w'] ?? 0
        );
    }

    private function deserializeArray(array $array): array
    {
        $output = [];
        foreach ($array as $key => $item) {
            $output[$key] = $this->deserializeValue($item);
        }

        return $output;
    }

    private function deserializeInterruptType(int $interruptType): InterruptType
    {
        return InterruptType::from($interruptType);
    }

    public function deserializeValue(string|array|null $value): mixed
    {
        if (is_array($value)) {
            return $this->deserializeArray($value);
        } else if (is_string($value) && str_starts_with($value, 's:')) {
            return substr($value, 2);
        } else if (is_string($value) && str_starts_with($value, 'i:')) {
            return (int)substr($value, 2);
        } else if (is_string($value) && str_starts_with($value, 'd:')) {
            return (float)substr($value, 2);
        } else if (is_string($value) && str_starts_with($value, 'b:')) {
            return substr($value, 2) === '1';
        } else if ($value === null) {
            return null;
        } else if (is_string($value) && str_starts_with($value, 'os:')) {
            return $this->deserializeStdObject(substr($value, 3));
        } else if (is_string($value) && str_starts_with($value, 'j:')) {
            return $this->deserializeJsonObject(substr($value, 2));
        } else if (is_string($value) && str_starts_with($value, 'o:')) {
            return $this->deserializeObject(substr($value, 2));
        } else {
            throw DeserializationException::unknownValueType((string)$value);
        }
    }

    protected function deserializeObject(string $objectId): SerializableObjectInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($objectId)) {
                return $provider->deserialize($objectId);
            }
        }

        throw DeserializationException::unknownObjectId($objectId);
    }

    protected function deserializeStdObject(string $data): \stdClass
    {
        $object = new \stdClass();
        $pairs = json_decode($data, true);
        foreach ($pairs as $parts) {
            if (!is_array($parts) || count($parts) !== 2) {
                throw DeserializationException::corruptStdClass();
            }
            $key = $parts[0];
            $value = $this->deserializeValue($parts[1]);
            $object->$key = $value;
        }

        return $object;
    }

    private function deserializeJsonObject(string $data)
    {
        $explodedData = explode(':', $data, 2);
        if (count($explodedData) !== 2) {
            throw DeserializationException::invalidJson('malformed JSON object entry — expected className:json');
        }

        $className = $explodedData[0];
        $json = $explodedData[1];

        if (!class_exists($className)) {
            throw DeserializationException::classNotFound($className);
        }

        if (!is_subclass_of($className, JsonSerializableInterface::class)) {
            throw DeserializationException::classNotJsonSerializable($className);
        }

        return $className::fromJson($json);
    }
}