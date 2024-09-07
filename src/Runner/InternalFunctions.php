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

    public const INTERNAL_FUNCTIONS_MAP = [
        self::ARRAY_GET_FUNCTION_NAME => 'arrayGet',
        self::ARRAY_COUNT_FUNCTION_NAME => 'arrayCount',
    ];

    public const EXTENDED_INTERNAL_FUNCTIONS_MAP = [
        self::SIGNAL_EXIST => 'signalExist',
        self::SIGNAL_NOT_EXIST => 'signalNotExist',
    ];

    public function arrayGet(array $array, int $index): mixed
    {
        return $array[$index] ?? null;
    }

    public function arrayCount(array $array): int
    {
        return count($array);
    }

    public function signalExist(Context $context, string $signalName): bool
    {
        return $context->isSignal($signalName);
    }

    public function signalNotExist(Context $context, string $signalName): bool
    {
        return !$context->isSignal($signalName);
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