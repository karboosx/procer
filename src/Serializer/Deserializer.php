<?php

namespace Karboosx\Procer\Serializer;

use Exception;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\IC\TokenInfo;
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

    private function deserializeValue(string|array|null $value): mixed
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
        } else if (is_string($value) && str_starts_with($value, 'o:')) {
            return $this->deserializeObject(substr($value, 2));
        } else {
            throw new Exception('Unsupported type');
        }
    }

    private function deserializeObject(string $objectId): SerializableObjectInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($objectId)) {
                return $provider->deserialize($objectId);
            }
        }

        throw new Exception('Object not found: ' . $objectId);
    }
}