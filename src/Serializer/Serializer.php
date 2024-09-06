<?php

namespace Karboosx\Procer\Serializer;

use Exception;
use Karboosx\Procer\Context;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\TokenInfo;
use Karboosx\Procer\Runner\Scope;

class Serializer
{
    public function serialize(Context $context): string
    {
        $process = $context->getProcess();

        $data = [
            'v' => 1,
            's' => $this->serializeScopes($process->scopes),
            'ic' => $this->serializeIC($process->ic),
            'i' => $this->serializeValue($process->currentInstructionIndex),
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
        ];
    }

    private function serializeIC(IC $ic): array
    {
        $output = [];
        foreach ($ic->getInstructions() as $instruction) {
            $output[] = $this->serializeInstruction($instruction);
        }

        return $output;
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

    private function serializeValue(mixed $value): string|array
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
            return 'N';
        } else if ($value instanceof SerializableObjectInterface) {
            return 'o:' . $value->getSerializeId();
        } else {
            throw new Exception('Unsupported type');
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
}