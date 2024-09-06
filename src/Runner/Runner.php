<?php

namespace Karboosx\Procer\Runner;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Exception\ObjectFunctionNotFoundException;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\IcPrinter;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\IC\TokenInfo;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\ObjectFunctionProviderInterface;

class Runner
{
    private Process $process;
    private array $functionProviders = [];
    private Context $context;
    private bool $running = false;

    private InternalFunctions $internalFunctions;
    private $signals = [];

    public function __construct()
    {
        $this->internalFunctions = new InternalFunctions();
        $this->process = new Process();
    }

    public function loadGlobalVariables(array $variables): void
    {
        $globalScope = $this->process->scopes[0];

        foreach ($variables as $name => $value) {
            $globalScope->setVariable($name, $value);
        }
    }

    public function loadCode(IC $ic): void
    {
        $this->process->ic = $ic;
    }

    /**
     * @throws RunnerException
     */
    public function run(): Context
    {
        $this->context = new Context($this);

        $this->running = true;

        $amountOfInstructions = count($this->process->ic->getInstructions());

        while ($this->process->currentInstructionIndex < $amountOfInstructions && $this->running) {
            $instruction = $this->process->ic->getInstruction($this->process->currentInstructionIndex);
            $this->executeInstruction($instruction);
        }

        return $this->context;
    }

    /**
     * @throws RunnerException
     */
    private function executeInstruction(ICInstruction $instruction): void
    {
        switch ($instruction->getType()) {
            case InstructionType::SET_VARIABLE:
                $this->executeLet($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::PUSH_VALUE:
                $this->executePushValue($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::PUSH_VARIABLE:
                $this->executePushVariable($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::MATH_OPERATOR:
                $this->executeMathOperator($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::IF_NOT_JMP:
                $this->executeIf($instruction);
                return;
            case InstructionType::JMP:
                $this->process->currentInstructionIndex = $instruction->getArgs()[0];
                return;
            case InstructionType::FUNCTION_CALL:
                if ($this->executeFunctionCall($instruction)) {
                    $this->process->currentInstructionIndex++;
                }
                return;
            case InstructionType::OBJECT_FUNCTION_CALL:
                if ($this->executeObjectFunctionCall($instruction)) {
                    $this->process->currentInstructionIndex++;
                }
                return;
            case InstructionType::INTERNAL_FUNCTION_CALL:
                $this->executeInternalFunctionCall($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::STOP:
                $this->process->currentInstructionIndex++;
                $this->running = false;
                return;
            case InstructionType::NOP:
                $this->process->currentInstructionIndex++;
                return;
        }

        throw new RunnerException('Unknown instruction type: ' . $instruction->getType()->value, $instruction->getTokenInfo());
    }

    private function executeLet(ICInstruction $instruction): void
    {
        $variableName = $instruction->getArgs()[0];
        $value = $this->getCurrentScope()->popStack();
        $this->getCurrentScope()->setVariable($variableName, $value);
    }

    private function executeIf(ICInstruction $instruction): void
    {
        $condition = $this->getCurrentScope()->popStack();
        if (!$condition) {
            $this->process->currentInstructionIndex = $instruction->getArgs()[0];
        } else {
            $this->process->currentInstructionIndex++;
        }
    }

    private function executePushValue(ICInstruction $instruction): void
    {
        $value = $instruction->getArgs()[0];
        $this->getCurrentScope()->pushStack($value);
    }

    private function executePushVariable(ICInstruction $instruction): void
    {
        $variableName = $instruction->getArgs()[0];
        $value = $this->getVariable($variableName);
        $this->getCurrentScope()->pushStack($value);
    }

    /**
     * @throws RunnerException
     */
    private function executeMathOperator(ICInstruction $instruction): void
    {
        $operator = $instruction->getArgs()[0];
        $right = $this->getCurrentScope()->popStack();
        $left = $this->getCurrentScope()->popStack();

        switch ($operator) {
            case '+':
                $this->getCurrentScope()->pushStack($left + $right);
                return;
            case '-':
                $this->getCurrentScope()->pushStack($left - $right);
                return;
            case '*':
                $this->getCurrentScope()->pushStack($left * $right);
                return;
            case '/':
                $this->getCurrentScope()->pushStack($left / $right);
                return;
            case '>':
                $this->getCurrentScope()->pushStack($left > $right);
                return;
            case '<':
                $this->getCurrentScope()->pushStack($left < $right);
                return;
            case '>=':
                $this->getCurrentScope()->pushStack($left >= $right);
                return;
            case '<=':
                $this->getCurrentScope()->pushStack($left <= $right);
                return;
            case '=':
            case 'is':
                $this->getCurrentScope()->pushStack($left == $right);
                return;
            case '!=':
            case 'is not':
                $this->getCurrentScope()->pushStack($left != $right);
                return;

        }

        throw new RunnerException('Unknown math operator: ' . $operator, $instruction->getTokenInfo());
    }

    /**
     * @throws RunnerException
     */
    private function executeFunctionCall(ICInstruction $instruction): bool
    {
        $functionName = $instruction->getArgs()[0];
        $provider = null;

        foreach ($this->functionProviders as $providerToCheck) {
            if ($providerToCheck instanceof FunctionProviderInterface && $providerToCheck->supports($functionName)) {
                $provider = $providerToCheck;
                break;
            }
        }

        if (!$provider) {
            throw new FunctionNotFoundException('Function not found: ' . $functionName, $functionName, $instruction->getTokenInfo());
        }

        $numberOfArguments = $instruction->getArgs()[1];
        $arguments = [];
        for ($i = 0; $i < $numberOfArguments; $i++) {
            $arguments[] = $this->getCurrentScope()->popStack();
        }

        $value = $provider->{$functionName}($this->context, ...$arguments);
        if ($value instanceof Interrupt) {
            $pointer = $instruction->getArgs()[2];
            $this->handleInterrupt($value, $pointer);
            return false;
        }

        $this->getCurrentScope()->pushStack($value);
        return true;
    }

    /**
     * @throws RunnerException
     */
    private function executeObjectFunctionCall(ICInstruction $instruction): bool
    {
        $objectVariable = $instruction->getArgs()[0];
        $object = $this->getCurrentScope()->getVariable($objectVariable);

        $functionName = $instruction->getArgs()[1];
        $provider = null;

        foreach ($this->functionProviders as $providerToCheck) {
            if (false === is_object($object)) {
                continue;
            }

            if ($providerToCheck instanceof ObjectFunctionProviderInterface && $providerToCheck->supports(get_class($object), $functionName)) {
                $provider = $providerToCheck;
                break;
            }
        }

        if (!$provider) {
            if (false === is_object($object)) {
                throw new ObjectFunctionNotFoundException('Object not found: ' . $objectVariable, $objectVariable, "", $instruction->getTokenInfo());
            }

            throw new ObjectFunctionNotFoundException('Function not found: ' . $functionName, $functionName, get_class($object), $instruction->getTokenInfo());
        }

        $numberOfArguments = $instruction->getArgs()[2];
        $arguments = [];
        for ($i = 0; $i < $numberOfArguments; $i++) {
            $arguments[] = $this->getCurrentScope()->popStack();
        }

        $object = $this->getCurrentScope()->popStack();
        $value = $provider->{$functionName}($this->context, $object, ...$arguments);

        if ($value instanceof Interrupt) {
            $pointer = $instruction->getArgs()[3];
            $this->handleInterrupt($value, $pointer);
            return false;
        }

        $this->getCurrentScope()->pushStack($value);
        return true;
    }

    private function executeInternalFunctionCall(ICInstruction $instruction): void
    {
        $functionName = $instruction->getArgs()[0];
        $numberOfArguments = $instruction->getArgs()[1];
        $arguments = [];
        for ($i = 0; $i < $numberOfArguments; $i++) {
            $arguments[] = $this->getCurrentScope()->popStack();
        }

        $value = $this->internalFunctions->{$functionName}($this->context, ...$arguments);
        $this->getCurrentScope()->pushStack($value);
    }

    public function getCurrentScope(): Scope
    {
        return $this->process->scopes[count($this->process->scopes) - 1];
    }

    public function getGlobalScope(): Scope
    {
        return $this->process->scopes[0];
    }

    public function getVariable(string $variableName): mixed
    {
        for ($i = count($this->process->scopes) - 1; $i >= 0; $i--) {
            $scope = $this->process->scopes[$i];
            if ($scope->hasVariable($variableName)) {
                return $scope->getVariable($variableName);
            }
        }

        throw new RunnerException('Variable not found: ' . $variableName, $this->getCurrentTokenInfo());
    }

    public function addFunctionProvider(FunctionProviderInterface|ObjectFunctionProviderInterface $provider): void
    {
        $this->functionProviders[] = $provider;
    }

    public function getProcess(): Process
    {
        return $this->process;
    }

    /** @noinspection PhpUnused */
    public function getContext(): Context
    {
        return $this->context;
    }

    public function isFinished(): bool
    {
        return $this->process->currentInstructionIndex >= count($this->process->ic->getInstructions());
    }

    /** @noinspection PhpUnused */
    public function isRunning(): bool
    {
        return $this->running;
    }

    public function loadProcess(Process $process): void
    {
        $this->process = $process;
    }

    /**
     * @throws RunnerException
     */
    private function handleInterrupt(Interrupt $value, int $beforeArgumentsPointer): void
    {
        $this->running = false;

        if ($value->getSignalType() === InterruptType::AFTER_EXECUTION) {
            $this->process->currentInstructionIndex++;
            $this->getCurrentScope()->pushStack($value->getData());
        } else if ($value->getSignalType() === InterruptType::BEFORE_EXECUTION) {
            $this->process->currentInstructionIndex = $beforeArgumentsPointer;
        } else {
            throw new RunnerException('Unknown signal type: ' . $value->getSignalType()->name);
        }
    }

    /** @noinspection PhpUnused */
    public function debugIc(): string
    {
        return (new IcPrinter())->prettify($this->process->ic);
    }

    private function getCurrentTokenInfo(): ?TokenInfo
    {
        return $this->process->ic->getInstructions()[$this->process->currentInstructionIndex]?->getTokenInfo();
    }

    public function isSignalExist(string $signalName): bool
    {
        return in_array($signalName, $this->signals, true);
    }

    public function loadSignals(array $signals): void
    {
        $this->signals = $signals;
    }
}