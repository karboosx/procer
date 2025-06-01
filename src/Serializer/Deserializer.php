<?php

namespace Karboosx\Procer\Serializer;

use Exception;
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

    public function deserialize(string $data): Process
    {
        $json = json_decode($data, true);

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
            $output[$this->deserializeValue($key)] = $this->deserializeValue($item);
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
        } else if (is_string($value) && str_starts_with($value, 'N')) {
            return null;
        } else if ($value === null) {
            return null;
        } else if (is_string($value) && str_starts_with($value, 'os:')) {
            return $this->deserializeStdObject(substr($value, 3));
        } else if (is_string($value) && str_starts_with($value, 'j:')) {
            return $this->deserializeJsonObject(substr($value, 2));
        } else if (is_string($value) && str_starts_with($value, 'o:')) {
            return $this->deserializeObject(substr($value, 2));
        } else {
            throw new Exception('Unsupported type');
        }
    }

    protected function deserializeObject(string $objectId): SerializableObjectInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($objectId)) {
                return $provider->deserialize($objectId);
            }
        }

        throw new Exception('Object not found: ' . $objectId);
    }

    protected function deserializeStdObject(string $data): \stdClass
    {
        $object = new \stdClass();
        $pairs = explode(',', $data);
        foreach ($pairs as $pair) {
            if (trim($pair) === '') {
                continue; // Skip empty pairs
            }
            
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2) {
                throw new Exception('Invalid stdClass format');
            }
            $key = $this->deserializeValue($parts[0]);
            $value = $this->deserializeValue($parts[1]);
            $object->$key = $value;
        }

        return $object;
    }

    private function deserializeJsonObject(string $data)
    {
        $explodedData = explode(':', $data, 2);
        if (count($explodedData) !== 2) {
            throw new Exception('Invalid JSON object format');
        }

        $className = $explodedData[0];
        $json = $explodedData[1];

        if (!class_exists($className)) {
            throw new Exception('Class not found: ' . $className);
        }

        if (!is_subclass_of($className, JsonSerializableInterface::class)) {
            throw new Exception('Class does not implement JsonSerializableInterface: ' . $className);
        }

        return $className::fromJson($json);
    }
}