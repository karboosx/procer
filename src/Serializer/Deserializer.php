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

        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw DeserializationException::invalidJson(json_last_error_msg());
        }

        if (!is_array($json)) {
            throw DeserializationException::invalidJson('root must be an object');
        }

        if (!array_key_exists('v', $json)) {
            throw DeserializationException::missingField('v');
        }

        if (!is_int($json['v'])) {
            throw DeserializationException::invalidJson("field 'v' must be an integer");
        }

        if ($json['v'] !== self::FORMAT_VERSION) {
            throw DeserializationException::versionMismatch(self::FORMAT_VERSION, $json['v']);
        }

        foreach (['s', 'ic', 'i', 'c'] as $field) {
            if (!array_key_exists($field, $json)) {
                throw DeserializationException::missingField($field);
            }
        }

        if (!is_array($json['s']) || count($json['s']) === 0) {
            throw DeserializationException::invalidJson("field 's' must be a non-empty array");
        }

        if (!is_array($json['ic'])) {
            throw DeserializationException::invalidJson("field 'ic' must be an object");
        }

        if (!is_int($json['c']) || $json['c'] < 0) {
            throw DeserializationException::invalidJson("field 'c' must be a non-negative integer");
        }

        $scopes = $this->deserializeScopes($json['s']);
        $ic = $this->deserializeIC($json['ic']);
        $index = $this->deserializeValue($json['i']);

        if (!is_int($index) || $index < 0) {
            throw DeserializationException::invalidJson("field 'i' must be a non-negative serialized integer");
        }

        $process = new Process();

        $process->scopes = $scopes;
        $process->ic = $ic;
        $process->cycles = $json['c'];
        $process->currentInstructionIndex = $index;
        $process->lastInterruptType = null;
        if (array_key_exists('l', $json) && $json['l'] !== null) {
            if (!is_int($json['l'])) {
                throw DeserializationException::invalidJson("field 'l' must be an integer or null");
            }
            $process->lastInterruptType = $this->deserializeInterruptType($json['l']);
        }

        return $process;
    }

    private function deserializeScopes(array $scopes): array
    {
        $output = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                throw DeserializationException::invalidJson("scope entry must be an object");
            }
            $output[] = $this->deserializeScope($scope);
        }

        return $output;
    }

    private function deserializeScope(array $scopeData): Scope
    {
        foreach (['v', 's', 'r', 'p'] as $field) {
            if (!array_key_exists($field, $scopeData)) {
                throw DeserializationException::missingField("scope.{$field}");
            }
        }

        if (!is_array($scopeData['v'])) {
            throw DeserializationException::invalidJson("field 'scope.v' must be an object");
        }

        if (!is_array($scopeData['s'])) {
            throw DeserializationException::invalidJson("field 'scope.s' must be an array");
        }

        if (!is_int($scopeData['p']) && $scopeData['p'] !== null) {
            throw DeserializationException::invalidJson("field 'scope.p' must be an integer or null");
        }

        $scope = new Scope();
        $scope->variables = $this->deserializeArray($scopeData['v']);
        $scope->stack = $this->deserializeArray($scopeData['s']);
        $scope->returnValue = $this->deserializeValue($scopeData['r']);
        $scope->returnPointer = $scopeData['p'];

        return $scope;
    }

    private function deserializeIC(array $ic): IC
    {
        foreach (['i', 'p'] as $field) {
            if (!array_key_exists($field, $ic)) {
                throw DeserializationException::missingField("ic.{$field}");
            }
        }

        if (!is_array($ic['i'])) {
            throw DeserializationException::invalidJson("field 'ic.i' must be an array");
        }

        if (!is_array($ic['p'])) {
            throw DeserializationException::invalidJson("field 'ic.p' must be an object");
        }

        $instructions = [];
        foreach ($ic['i'] as $instruction) {
            if (!is_array($instruction)) {
                throw DeserializationException::invalidJson("instruction entry must be an object");
            }
            $instructions[] = $this->deserializeInstruction($instruction);
        }

        return new IC($instructions, $ic['p']);
    }

    private function deserializeInstruction(array $instruction): ICInstruction
    {
        foreach (['o', 'a', 't'] as $field) {
            if (!array_key_exists($field, $instruction)) {
                throw DeserializationException::missingField("instruction.{$field}");
            }
        }

        if (!is_int($instruction['o'])) {
            throw DeserializationException::invalidJson("instruction opcode must be an integer");
        }

        if (!is_array($instruction['a'])) {
            throw DeserializationException::invalidJson("instruction arguments must be an array");
        }

        if (!is_array($instruction['t']) && $instruction['t'] !== null) {
            throw DeserializationException::invalidJson("instruction token info must be an object or null");
        }

        try {
            $instructionType = InstructionType::from($instruction['o']);
        } catch (\ValueError) {
            throw DeserializationException::invalidJson("unknown instruction opcode '{$instruction['o']}'");
        }

        return new ICInstruction(
            $instructionType,
            $this->deserializeArray($instruction['a']),
            $this->deserializeTokenInfo($instruction['t'])
        );
    }

    private function deserializeTokenInfo(array|null $tokenInfo): TokenInfo|null
    {
        if ($tokenInfo === null) {
            return null;
        }

        foreach (['l', 'p'] as $field) {
            if (!array_key_exists($field, $tokenInfo)) {
                throw DeserializationException::missingField("token.{$field}");
            }
        }

        foreach (['l', 'p'] as $field) {
            if (!is_int($tokenInfo[$field])) {
                throw DeserializationException::invalidJson("token field '{$field}' must be an integer");
            }
        }

        if (array_key_exists('w', $tokenInfo) && !is_int($tokenInfo['w'])) {
            throw DeserializationException::invalidJson("token field 'w' must be an integer");
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
        try {
            return InterruptType::from($interruptType);
        } catch (\ValueError) {
            throw DeserializationException::invalidJson("unknown interrupt type '{$interruptType}'");
        }
    }

    public function deserializeValue(mixed $value): mixed
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
            if (is_scalar($value)) {
                throw DeserializationException::unknownValueType((string)$value);
            }

            throw DeserializationException::invalidJson('serialized value must be a string, array, or null');
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
        if (!is_array($pairs)) {
            throw DeserializationException::corruptStdClass();
        }

        foreach ($pairs as $parts) {
            if (!is_array($parts) || count($parts) !== 2) {
                throw DeserializationException::corruptStdClass();
            }
            $key = $parts[0];
            if (!is_string($key) && !is_int($key)) {
                throw DeserializationException::corruptStdClass();
            }
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
