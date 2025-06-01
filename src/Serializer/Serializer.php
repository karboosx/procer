<?php

namespace Karboosx\Procer\Serializer;

use Exception;
use Karboosx\Procer\Context;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\TokenInfo;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Runner\Scope;

class Serializer
{
    public function serialize(Process $process): string
    {
        $data = [
            'v' => 1,
            's' => $this->serializeScopes($process->scopes),
            'ic' => $this->serializeIC($process->ic),
            'i' => $this->serializeValue($process->currentInstructionIndex),
            'c' => $process->cycles,
            'l' => $process->lastInterruptType?->value,
        ];

        return json_encode($data);
    }

    private function serializeScopes(array $scopes): array
    {
        $output = [];
        foreach ($scopes as $scope) {
            $output[] = $this->serializeScope($scope);
        }

        return $output;
    }

    private function serializeScope(Scope $scope): array
    {
        return [
            'v' => $this->serializeArray($scope->getVariables()),
            's' => $this->serializeArray($scope->getStack()),
            'r' => $this->serializeValue($scope->returnValue),
            'p' => $scope->returnPointer,
        ];
    }

    private function serializeIC(IC $ic): array
    {
        $instructions = [];
        foreach ($ic->getInstructions() as $instruction) {
            $instructions[] = $this->serializeInstruction($instruction);
        }

        $procedures = $ic->getProcedurePointers();

        return [
            'i' => $instructions,
            'p' => $procedures,
        ];
    }

    private function serializeInstruction(ICInstruction $instruction): array
    {
        return [
            'o' => $instruction->getType(),
            'a' => $this->serializeArray($instruction->getArgs()),
            't' => $this->serializeTokenInfo($instruction->getTokenInfo()),
        ];
    }

    private function serializeTokenInfo(?TokenInfo $tokenInfo): array|null
    {
        if ($tokenInfo === null) {
            return null;
        }

        return [
            'l' => $tokenInfo->line,
            'p' => $tokenInfo->linePosition,
            'w' => $tokenInfo->width,
        ];
    }

    public function serializeValue(mixed $value): string|array|null
    {
        if (is_array($value)) {
            return $this->serializeArray($value);
        } else if (is_string($value)) {
            return 's:' . $value;
        } else if (is_int($value)) {
            return 'i:' . $value;
        } else if (is_float($value)) {
            return 'd:' . $value;
        } else if (is_bool($value)) {
            return 'b:' . ($value ? '1' : '0');
        } else if (is_null($value)) {
            return null;
        } else if ($value instanceof SerializableObjectInterface) {
            return 'o:' . $value->getSerializeId();
        } else if ($value instanceof JsonSerializableInterface) {
            $data = $value->toJson();
            if ($data === false) {
                throw new Exception('Failed to serialize JSON: ' . json_last_error_msg());
            }
            $className = get_class($value);
            return 'j:'. $className . ':' . $data;
        } else if (is_object($value)) {
            if ($value instanceof \stdClass) {
                return $this->serializeStdClass($value);
            }
            throw new Exception('Unsupported object: ' . get_class($value));
        } else {
            throw new Exception('Unsupported type: ' . gettype($value));
        }
    }

    private function serializeArray(array $array): array
    {
        $output = [];
        foreach ($array as $key => $value) {
            $output[$this->serializeValue($key)] = $this->serializeValue($value);
        }

        return $output;
    }


    private function serializeStdClass(\stdClass $stdClass): string
    {
        $output = [];
        foreach ($stdClass as $key => $value) {
            $output[] = $key . ':' . $this->serializeValue($value);
        }

        return 'os:' . implode(',', $output);
    }
}