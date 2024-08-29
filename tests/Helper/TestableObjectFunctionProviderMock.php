<?php

namespace Procer\Tests\Helper;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Constraint\IsIdentical;
use Procer\FunctionProviderInterface;
use Procer\ObjectFunctionProviderInterface;

readonly class TestableObjectFunctionProviderMock implements ObjectFunctionProviderInterface
{
    public function __construct(
        public string $name,
        public array  $requiredArgs,
        public mixed  $returnValue = null,
        public string $objectName = 'obj'
    )
    {
    }


    public function __call(string $name, array $arguments)
    {
        if ($name !== $this->name) {
            throw new \BadMethodCallException("Method $name does not exist.");
        }

        $arguments = array_slice($arguments, 2, count($this->requiredArgs));

        Assert::assertThat($name, new IsIdentical($this->name));

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

    public function supports(string $className, string $functionName): bool
    {
        return $this->name === $functionName;
    }
}