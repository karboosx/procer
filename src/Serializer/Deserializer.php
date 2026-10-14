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
    private int $depth = 0;
    /**
     * @var DeserializeObjectProviderInterface[]
     */
    private array $providers;

    public function __construct(DeserializeObjectProviderInterface ...$providers)
    {
        $this->providers = $providers;
    }

    const FORMAT_VERSION = 2;

    public function deserialize(string $data): Process
    {
        if (strlen($data) > Serializer::MAX_SERIALIZED_BYTES) {
            throw DeserializationException::invalidJson('maximum serialized size exceeded');
        }
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

        if (!in_array($json['v'], [1, self::FORMAT_VERSION], true)) {
            throw DeserializationException::versionMismatch(self::FORMAT_VERSION, $json['v']);
        }

        foreach (['s', 'ic', 'i', 'c'] as $field) {
            if (!array_key_exists($field, $json)) {
                throw DeserializationException::missingField($field);
            }
        }

        if (!is_array($json['s']) || !array_is_list($json['s']) || count($json['s']) === 0) {
            throw DeserializationException::invalidJson("field 's' must be a non-empty array");
        }

        if (!is_array($json['ic'])) {
            throw DeserializationException::invalidJson("field 'ic' must be an object");
        }

        if (!is_int($json['c']) || $json['c'] < 0) {
            throw DeserializationException::invalidJson("field 'c' must be a non-negative integer");
        }

        $scopes = $this->deserializeScopes($json['s'], $json['v']);
        $ic = $this->deserializeIC($json['ic']);
        if ($json['v'] === 1) {
            foreach ($ic->getInstructions() as $instruction) {
                if ($instruction->getType()->value >= InstructionType::BUILD_RECORD->value) {
                    throw DeserializationException::invalidJson('new instructions require snapshot format version 2');
                }
            }
        }
        $index = $this->deserializeValue($json['i']);

        if (!is_int($index) || $index < 0) {
            throw DeserializationException::invalidJson("field 'i' must be a non-negative serialized integer");
        }
        $instructionCount = count($ic->getInstructions());
        if ($index > $instructionCount) {
            throw DeserializationException::invalidJson('instruction index is out of range');
        }
        foreach ($scopes as $scopeIndex => $scope) {
            if (($scopeIndex === 0 && $scope->returnPointer !== null) ||
                ($scopeIndex > 0 && ($scope->returnPointer === null || $scope->returnPointer < 0 || $scope->returnPointer > $instructionCount))) {
                throw DeserializationException::invalidJson('scope return pointer is out of range');
            }
            $pointer = isset($scopes[$scopeIndex + 1]) ? $scopes[$scopeIndex + 1]->returnPointer : $index;
            $this->validateSteps($scope, $ic, $pointer);
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

    private function deserializeScopes(array $scopes, int $version): array
    {
        $output = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) {
                throw DeserializationException::invalidJson("scope entry must be an object");
            }
            $restored = $this->deserializeScope($scope);
            if ($version >= 2) {
                if (!isset($scope['h']) || !is_array($scope['h']) || !array_is_list($scope['h'])) {
                    throw DeserializationException::invalidJson('scope.h must be a step frame array');
                }
                $restored->steps = $this->deserializeArray($scope['h']);
            } elseif (isset($scope['h']) && $scope['h'] !== []) {
                throw DeserializationException::invalidJson('step frames require snapshot format version 2');
            }
            $output[] = $restored;
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

        if (!is_array($scopeData['s']) || !array_is_list($scopeData['s'])) {
            throw DeserializationException::invalidJson("field 'scope.s' must be an array");
        }

        if (!is_int($scopeData['p']) && $scopeData['p'] !== null) {
            throw DeserializationException::invalidJson("field 'scope.p' must be an integer or null");
        }

        $scope = new Scope();
        $scope->variables = $this->deserializeArray($scopeData['v']);
        $scope->stack = $this->deserializeArray($scopeData['s']);
        $scope->returnValue = $this->deserializeValue($scopeData['r']);
        $scope->externalReturnValue = $this->deserializeValue($scopeData['e'] ?? null);
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

        if (!is_array($ic['i']) || !array_is_list($ic['i'])) {
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

        $count = count($instructions);
        foreach ($ic['p'] as $pointer) {
            if (!is_int($pointer) || $pointer < 0 || $pointer >= $count) {
                throw DeserializationException::invalidJson('procedure pointer is out of range');
            }
        }
        foreach ($instructions as $position => $instruction) {
            $args = $instruction->getArgs();
            $pointer = match ($instruction->getType()) {
                InstructionType::JMP, InstructionType::IF_NOT_JMP, InstructionType::NEXT_LOOP => $args[0],
                InstructionType::FUNCTION_CALL => $args[2],
                InstructionType::OBJECT_FUNCTION_CALL => $args[3],
                default => null,
            };
            if ($pointer !== null && $pointer > $count) {
                throw DeserializationException::invalidJson('instruction jump pointer is out of range');
            }
            if ($instruction->getType() === InstructionType::ENTER_STEP &&
                ($args[1] <= $position || $args[1] > $args[3] || $args[3] > $count ||
                 ($args[2] !== '' && $args[1] === $args[3]) || ($args[2] === '' && $args[1] !== $args[3]))) {
                throw DeserializationException::invalidJson('invalid step instruction boundaries');
            }
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

        if (!is_array($instruction['a']) || !array_is_list($instruction['a'])) {
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

        $args = $this->deserializeArray($instruction['a']);
        $this->validateArguments($instructionType, $args);
        return new ICInstruction(
            $instructionType,
            $args,
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
            if (!is_int($tokenInfo[$field]) || $tokenInfo[$field] < ($field === 'l' ? 1 : 0)) {
                throw DeserializationException::invalidJson("token field '{$field}' must be an integer");
            }
        }

        if (array_key_exists('w', $tokenInfo) && (!is_int($tokenInfo['w']) || $tokenInfo['w'] < 0)) {
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

    private function validateArguments(InstructionType $type, array $args): void
    {
        $schema = match ($type) {
            InstructionType::BUILD_RECORD => array_fill(0, count($args), 'field'),
            InstructionType::ENTER_STEP => ['text', 'integer', 'text', 'integer'],
            InstructionType::NEXT_LOOP => ['integer', 'integer'],
            InstructionType::PUSH_VALUE => ['value'],
            InstructionType::PUSH_VARIABLE, InstructionType::SET_VARIABLE,
            InstructionType::SET_LOCAL_VARIABLE, InstructionType::PUSH_OBJECT_ACCESS,
            InstructionType::PUSH_BUILD_IN, InstructionType::MATH_OPERATOR => ['string'],
            InstructionType::JMP, InstructionType::IF_NOT_JMP, InstructionType::ASSERT_STACK_COUNT => ['integer'],
            InstructionType::FUNCTION_CALL => ['string', 'integer', 'integer'],
            InstructionType::OBJECT_FUNCTION_CALL => ['string', 'string', 'integer', 'integer'],
            InstructionType::INTERNAL_FUNCTION_CALL => ['string', 'integer'],
            InstructionType::RET => ['boolean'],
            InstructionType::WAIT_FOR_SIGNAL => array_merge(['boolean'], array_fill(0, max(0, count($args) - 1), 'string')),
            default => [],
        };
        if (count($args) !== count($schema) || ($type === InstructionType::WAIT_FOR_SIGNAL && count($args) < 2)) {
            throw DeserializationException::invalidJson('wrong instruction argument count for ' . $type->name);
        }
        foreach ($schema as $i => $kind) {
            $valid = match ($kind) {
                'text' => is_string($args[$i]),
                'field' => is_string($args[$i]) && !str_contains($args[$i], "\0"),
                'string' => is_string($args[$i]) && $args[$i] !== '',
                'integer' => is_int($args[$i]) && $args[$i] >= 0,
                'boolean' => is_bool($args[$i]) || $args[$i] === 0 || $args[$i] === 1,
                default => true,
            };
            if (!$valid) {
                throw DeserializationException::invalidJson('invalid instruction argument for ' . $type->name);
            }
        }
        if ($type === InstructionType::BUILD_RECORD && ($args === [] || count(array_unique($args, SORT_STRING)) !== count($args))) {
            throw DeserializationException::invalidJson('record requires unique field names');
        }
        if ($type === InstructionType::PUSH_BUILD_IN && !in_array($args[0], ['true', 'false', 'null'], true)) {
            throw DeserializationException::invalidJson('unknown built-in value');
        }
        if ($type === InstructionType::MATH_OPERATOR && !in_array($args[0], ['+', '-', '*', '/', '%', '.', '=', 'is', '!=', 'is_not', '<', '>', '<=', '>=', 'and', 'or'], true)) {
            throw DeserializationException::invalidJson('unknown math operator');
        }
    }

    private function deserializeInterruptType(int $interruptType): InterruptType
    {
        try {
            return InterruptType::from($interruptType);
        } catch (\ValueError) {
            throw DeserializationException::invalidJson("unknown interrupt type '{$interruptType}'");
        }
    }

    private function validateSteps(Scope $scope, IC $ic, ?int $pointer): void
    {
        if (count($scope->steps) > 128) {
            throw DeserializationException::invalidJson('too many step frames');
        }
        $previousStart = -1;
        $previousEnd = count($ic->getInstructions()) + 1;
        $previousStack = 0;
        $expectedFrames = [];
        foreach ($ic->getInstructions() as $position => $instruction) {
            if ($instruction->getType() !== InstructionType::ENTER_STEP) { continue; }
            [, $handler, , $end] = $instruction->getArgs();
            // The success-path JMP follows LEAVE_STEP, so that one boundary has no frame.
            if ($pointer > $position && $pointer < $end && $pointer !== $handler - 1) {
                $expectedFrames[] = $position;
            }
        }
        if (array_column($scope->steps, 'instruction') !== $expectedFrames) {
            throw DeserializationException::invalidJson('active step frames do not match instruction position');
        }
        foreach ($scope->steps as $frame) {
            if (!is_array($frame) || count($frame) !== 5 ||
                !isset($frame['instruction'], $frame['stack'], $frame['handling'], $frame['hadBinding']) ||
                !array_key_exists('bindingValue', $frame) ||
                !is_int($frame['instruction']) || !is_int($frame['stack']) ||
                !is_bool($frame['handling']) || !is_bool($frame['hadBinding']) ||
                $frame['instruction'] <= $previousStart || $frame['instruction'] >= count($ic->getInstructions()) ||
                $frame['stack'] < $previousStack || $frame['stack'] > count($scope->stack)) {
                throw DeserializationException::invalidJson('invalid step frame');
            }
            $instruction = $ic->getInstruction($frame['instruction']);
            if ($instruction->getType() !== InstructionType::ENTER_STEP) {
                throw DeserializationException::invalidJson('step frame must reference ENTER_STEP');
            }
            [$name, $handler, $binding, $end] = $instruction->getArgs();
            if ($end > $previousEnd || $pointer === null || $pointer <= $frame['instruction'] || $pointer >= $end ||
                ($frame['handling'] && ($binding === '' || $pointer < $handler || !$scope->hasVariable($binding))) ||
                (!$frame['handling'] && ($pointer >= $handler || $frame['hadBinding'] || $frame['bindingValue'] !== null)) ||
                (!$frame['hadBinding'] && $frame['bindingValue'] !== null)) {
                throw DeserializationException::invalidJson('inconsistent step frame state');
            }
            $previousStart = $frame['instruction'];
            $previousEnd = $end;
            $previousStack = $frame['stack'];
        }
    }

    public function deserializeValue(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > Serializer::MAX_SERIALIZED_BYTES) {
            throw DeserializationException::invalidJson('maximum serialized value size exceeded');
        }
        if ($this->depth >= 128) {
            throw DeserializationException::invalidJson('maximum value depth exceeded');
        }
        $this->depth++;
        try {
            return $this->deserializeValueBody($value);
        } finally {
            $this->depth--;
        }
    }

    private function deserializeValueBody(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->deserializeArray($value);
        } else if (is_string($value) && str_starts_with($value, 's:')) {
            return substr($value, 2);
        } else if (is_string($value) && str_starts_with($value, 'i:')) {
            $number = substr($value, 2);
            if (!preg_match('/^-?(0|[1-9][0-9]*)$/D', $number) || filter_var($number, FILTER_VALIDATE_INT) === false) {
                throw DeserializationException::invalidJson('invalid serialized integer');
            }
            return (int)$number;
        } else if (is_string($value) && str_starts_with($value, 'd:')) {
            $number = substr($value, 2);
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $number) || !is_finite((float)$number)) {
                throw DeserializationException::invalidJson('invalid serialized float');
            }
            return (float)$number;
        } else if (is_string($value) && str_starts_with($value, 'b:')) {
            if ($value !== 'b:0' && $value !== 'b:1') {
                throw DeserializationException::invalidJson('invalid serialized boolean');
            }
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
        if (!is_array($pairs) || !array_is_list($pairs)) {
            throw DeserializationException::corruptStdClass();
        }

        foreach ($pairs as $parts) {
            if (!is_array($parts) || !array_is_list($parts) || count($parts) !== 2) {
                throw DeserializationException::corruptStdClass();
            }
            $key = $parts[0];
            if (!is_string($key) && !is_int($key)) {
                throw DeserializationException::corruptStdClass();
            }
            if (is_string($key) && str_starts_with($key, "\0")) {
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
