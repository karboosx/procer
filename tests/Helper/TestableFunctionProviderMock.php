<?php

namespace Procer\Tests\Helper;

use Procer\FunctionProviderInterface;

readonly class TestableFunctionProviderMock implements FunctionProviderInterface
{
    public function __construct(
        public string $name,
        public array  $requiredArgs,
        public mixed  $returnValue = null
    )
    {
    }

    public function supports(string $functionName): bool
    {
        return $this->name === $functionName;
    }

    public function __call(string $name, array $arguments)
    {
        if ($name !== $this->name) {
            throw new \BadMethodCallException("Method $name does not exist.");
        }

        $arguments = array_slice($arguments, 1, count($this->requiredArgs));

        foreach ($this->requiredArgs as $index => $requiredArg) {
            if (!isset($arguments[$index])) {
                throw new \InvalidArgumentException("Argument $index is required.");
            }

            if ($arguments[$index] !== $requiredArg) {
                throw new \InvalidArgumentException("Argument $index must be $requiredArg.");
            }
        }

        return $this->returnValue;
    }
}