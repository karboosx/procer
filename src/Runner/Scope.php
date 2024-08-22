<?php

namespace Procer\Runner;

class Scope
{
    public array $variables = [];

    public array $stack = [];

    public function setVariable(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    public function getVariable(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    public function pushStack(mixed $value): void
    {
        $this->stack[] = $value;
    }

    public function popStack(): mixed
    {
        return array_pop($this->stack);
    }

    public function getStack(): array
    {
        return $this->stack;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function hasVariable(string $variableName): bool
    {
        return isset($this->variables[$variableName]);
    }
}