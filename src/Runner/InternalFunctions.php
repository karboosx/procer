<?php

namespace Karboosx\Procer\Runner;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\RunnerException;

class InternalFunctions
{
    public const ARRAY_GET_FUNCTION_NAME = 'array_get';
    public const ARRAY_COUNT_FUNCTION_NAME = 'array_count';
    public const SIGNAL_EXIST = 'signal_exist';
    public const SIGNAL_NOT_EXIST = 'signal_not_exist';
    public const EXISTS_FUNCTION_NAME = 'variable_exists';
    public const ARRAY_VALUES = 'array_values';
    public const RANGE_CONTINUES = 'range_continues';
    public const RANGE_ADVANCE = 'range_advance';

    public const INTERNAL_FUNCTIONS_MAP = [
        self::ARRAY_GET_FUNCTION_NAME => 'arrayGet',
        self::ARRAY_COUNT_FUNCTION_NAME => 'arrayCount',
        self::ARRAY_VALUES => 'arrayValues',
        self::RANGE_CONTINUES => 'rangeContinues',
        self::RANGE_ADVANCE => 'rangeAdvance',
    ];

    public const EXTENDED_INTERNAL_FUNCTIONS_MAP = [
        self::SIGNAL_EXIST => 'signalExist',
        self::SIGNAL_NOT_EXIST => 'signalNotExist',
        self::EXISTS_FUNCTION_NAME => 'variableExists',
    ];

    public function arrayGet(array $array, int $index): mixed
    {
        return $array[$index] ?? null;
    }

    public function arrayCount(array $array): int
    {
        return count($array);
    }

    public function arrayValues(array $array): array
    {
        return array_values($array);
    }

    public function rangeContinues(mixed $value, mixed $end, mixed $step): bool
    {
        foreach ([$value, $end, $step] as $number) {
            if ((!is_int($number) && !is_float($number)) || !is_finite((float)$number)) {
                throw new RunnerException('Range bounds and step must be finite numbers');
            }
        }
        if ($step == 0) {
            throw new RunnerException('Range step must not be zero');
        }
        return $step > 0 ? $value <= $end : $value >= $end;
    }

    public function rangeAdvance(mixed $value, mixed $step): int|float
    {
        $this->rangeContinues($value, $value, $step);
        $next = $value + $step;
        if (!is_finite((float)$next) || ($step > 0 ? $next <= $value : $next >= $value)) {
            throw new RunnerException('Range step cannot advance the loop variable');
        }
        return $next;
    }

    public function signalExist(Context $context, string $signalName): bool
    {
        return $context->isSignal($signalName);
    }

    public function signalNotExist(Context $context, string $signalName): bool
    {
        return !$context->isSignal($signalName);
    }

    public function variableExists(Context $context, string $variableName): bool
    {
        return $context->has($variableName);
    }

    public function __call(string $name, array $arguments)
    {
        $context = $arguments[0];

        if (!($context instanceof Context)) {
            throw new \Exception("Internal function must receive a context as first argument");
        }

        $arguments = array_slice($arguments, 1);

        if (array_key_exists($name, self::INTERNAL_FUNCTIONS_MAP)) {
            return $this->{self::INTERNAL_FUNCTIONS_MAP[$name]}(...$arguments);
        }

        if (array_key_exists($name, self::EXTENDED_INTERNAL_FUNCTIONS_MAP)) {
            return $this->{self::EXTENDED_INTERNAL_FUNCTIONS_MAP[$name]}($context, ...$arguments);
        }

        throw new RunnerException("Internal function not found: $name");
    }
}
