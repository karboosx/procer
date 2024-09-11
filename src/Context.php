<?php

namespace Karboosx\Procer;

use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Runner\Runner;
use Karboosx\Procer\Serializer\Serializer;

readonly class Context
{
    public function __construct(
        private Runner $runner
    )
    {
    }

    public function get(string $variableName)
    {
        return $this->runner->getCurrentScope()->getVariable($variableName);
    }

    public function has(string $variableName): bool
    {
        return $this->runner->getCurrentScope()->hasVariable($variableName);
    }

    public function getGlobal(string $variableName)
    {
        return $this->runner->getGlobalScope()->getVariable($variableName);
    }

    public function set(string $variableName, mixed $value): void
    {
        $this->runner->getCurrentScope()->setVariable($variableName, $value);
    }

    public function setGlobal(string $variableName, mixed $value): void
    {
        $this->runner->getGlobalScope()->setVariable($variableName, $value);
    }

    public function isFinished(): bool
    {
        return $this->runner->isFinished();
    }

    public function getProcess(): Process
    {
        return $this->runner->getProcess();
    }

    public function serialize(): string
    {
        return (new Serializer())->serialize($this->getProcess());
    }

    public function isSignal(string $signalName): bool
    {
        return $this->runner->isSignalExist($signalName);
    }

    public function getReturnValue(): mixed
    {
        return $this->runner->getReturnValue();
    }
}