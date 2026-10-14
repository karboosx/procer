<?php

namespace Karboosx\Procer\Serializer;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\SerializationException;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\TokenInfo;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Runner\Scope;

class Serializer
{
    public const MAX_SERIALIZED_BYTES = 4194304;
    private int $depth = 0;
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

        $result = json_encode($data);
        if ($result === false) {
            throw SerializationException::jsonEncodeFailed('process', json_last_error_msg());
        }
        $this->checkSize($result);
        return $result;
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
        ] + ($scope->externalReturnValue === null ? [] : ['e' => $this->serializeValue($scope->externalReturnValue)]);
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
        if ($this->depth >= 128) {
            throw new SerializationException('Maximum serialization depth exceeded (possibly a cyclic value)');
        }
        $this->depth++;
        try {
            $serialized = $this->serializeValueBody($value);
            if (is_string($serialized)) {
                $this->checkSize($serialized);
            }
            return $serialized;
        } finally {
            $this->depth--;
        }
    }

    private function serializeValueBody(mixed $value): string|array|null
    {
        if (is_array($value)) {
            return $this->serializeArray($value);
        } else if (is_string($value)) {
            $this->checkSize($value);
            return 's:' . $value;
        } else if (is_int($value)) {
            return 'i:' . $value;
        } else if (is_float($value)) {
            if (!is_finite($value)) {
                throw new SerializationException('Cannot serialize a non-finite float');
            }
            // 17 significant digits round-trip a double; %h ignores LC_NUMERIC
            // and the process-wide precision/serialize_precision settings.
            return 'd:' . sprintf('%.17h', $value);
        } else if (is_bool($value)) {
            return 'b:' . ($value ? '1' : '0');
        } else if (is_null($value)) {
            return null;
        } else if ($value instanceof SerializableObjectInterface) {
            return 'o:' . $value->getSerializeId();
        } else if ($value instanceof JsonSerializableInterface) {
            $data = $value->toJson();
            if ($data === false) {
                throw SerializationException::jsonEncodeFailed(get_class($value), json_last_error_msg());
            }
            $className = get_class($value);
            return 'j:'. $className . ':' . $data;
        } else if (is_object($value)) {
            if ($value instanceof \stdClass) {
                return $this->serializeStdClass($value);
            }
            throw SerializationException::unsupportedObject(get_class($value));
        } else {
            throw SerializationException::unsupportedType(gettype($value));
        }
    }

    private function serializeArray(array $array): array
    {
        $output = [];
        foreach ($array as $key => $value) {
            $output[$key] = $this->serializeValue($value);
        }

        return $output;
    }


    private function serializeStdClass(\stdClass $stdClass): string
    {
        $output = [];
        foreach ($stdClass as $key => $value) {
            $output[] = [$key,$this->serializeValue($value)];
        }

        $result = json_encode($output);
        if ($result === false) {
            throw SerializationException::jsonEncodeFailed('stdClass', json_last_error_msg());
        }
        $this->checkSize($result);

        return 'os:' . $result;
    }

    private function checkSize(string $value): void
    {
        if (strlen($value) > self::MAX_SERIALIZED_BYTES) {
            throw new SerializationException('Maximum serialized size exceeded');
        }
    }
}
