<?php

namespace Karboosx\Procer\Tests\Helper;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Constraint\IsIdentical;
use Karboosx\Procer\FunctionProviderInterface;

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

        Assert::assertThat($name, new IsIdentical($this->name));

        $arguments = array_slice($arguments, 1, count($this->requiredArgs));

        foreach ($this->requiredArgs as $index => $requiredArg) {
            if (!isset($arguments[$index])) {
                throw new \InvalidArgumentException("Argument $index is required.");
            }

            if ($arguments[$index] !== $requiredArg) {
                throw new \InvalidArgumentException("Argument $index must be $requiredArg.");
            }

            Assert::assertThat($arguments[$index], new IsIdentical($requiredArg));
        }

        return $this->returnValue;
    }
}