<?php

namespace Karboosx\Procer\Runner;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\ObjectFunctionNotFoundException;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\IC\IC;
use Karboosx\Procer\IC\ICInstruction;
use Karboosx\Procer\IC\IcPrinter;
use Karboosx\Procer\IC\InstructionType;
use Karboosx\Procer\IC\TokenInfo;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptReason;
use Karboosx\Procer\Interrupt\InterruptType;
use Karboosx\Procer\ObjectFunctionProviderInterface;

class Runner
{
    private Process $process;
    private array $functionProviders = [];
    private Context $context;
    private bool $running = false;
    private mixed $interruptData = null;
    private ?InterruptReason $interruptReason = null;

    private InternalFunctions $internalFunctions;
    private array $signals = [];
    private ?array $waitForSignalValue = null;

    private int $maxCycles = -1;

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

    public function loadInstructions(IC $ic): void
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
        $this->interruptReason = null;
        $this->interruptData = null;

        $amountOfInstructions = count($this->process->ic->getInstructions());

        while ($this->process->currentInstructionIndex < $amountOfInstructions && $this->running) {
            $instruction = $this->process->ic->getInstruction($this->process->currentInstructionIndex);
            $this->executeInstruction($instruction);
            $this->process->cycles++;

            if ($this->maxCycles >= 0 && $this->process->cycles >= $this->maxCycles) {
                throw new MaxCyclesException('Max cycles reached', $instruction->getTokenInfo());
            }
        }

        if ($this->isFinished()) {
            $this->running = false;
            $this->waitForSignalValue = null;
        }
        return $this->context;
    }


    /**
     * @throws RunnerException
     */
    public function runExpression()
    {
        $this->run();

        return $this->getCurrentScope()->popStack() ?? null;
    }

    /**
     * @throws RunnerException
     */
    private function executeInstruction(ICInstruction $instruction): void
    {
        $this->waitForSignalValue = null;
        $this->process->lastInterruptType = null;

        switch ($instruction->getType()) {
            case InstructionType::SET_VARIABLE:
                $this->executeSetVariable($instruction);
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
            case InstructionType::PUSH_BUILD_IN:
                $this->executePushBuildIn($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::PUSH_PEEK_LAST_VALUE:
                $value = $this->getCurrentScope()->popStack();
                $this->getCurrentScope()->pushStack($value);
                $this->getCurrentScope()->pushStack($value);
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
                $this->interruptReason = InterruptReason::STOP;
                return;
            case InstructionType::WHILE_STOP:
                $this->process->currentInstructionIndex++;
                $this->running = false;
                $this->interruptReason = InterruptReason::WHILE_STOPPING;
                return;
            case InstructionType::NOP:
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::WAIT_FOR_SIGNAL:
                $this->executeWaitForSignal($instruction);
                return;
            case InstructionType::PUSH_FUNCTION_RESULT:
                $this->getCurrentScope()->pushStack($this->getCurrentScope()->returnValue);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::ASSERT_STACK_COUNT:
                $this->executeAssertStackCount($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::RET:
                $this->executeRet($instruction);
                return;
            case InstructionType::PUSH_OBJECT_ACCESS:
                $this->executeObjectAccess($instruction);
                $this->process->currentInstructionIndex++;
                return;
            case InstructionType::INVERT_VALUE:
                $this->getCurrentScope()->pushStack(!$this->getCurrentScope()->popStack());
                $this->process->currentInstructionIndex++;
                return;
        }

        throw new RunnerException('Unknown instruction type: ' . $instruction->getType()->value, $instruction->getTokenInfo());
    }

    private function executeSetVariable(ICInstruction $instruction): void
    {
        $variableName = $instruction->getArgs()[0];
        $value = $this->getCurrentScope()->popStack();

        if ($this->getGlobalScope()->hasVariable($variableName)) {
            $this->getGlobalScope()->setVariable($variableName, $value);
            return;
        }

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

    /**
     * @throws RunnerException
     */
    private function executePushVariable(ICInstruction $instruction): void
    {
        $variableName = $instruction->getArgs()[0];

        $value = $this->getVariable($variableName);
        $this->getCurrentScope()->pushStack($value);
    }

    /**
     * @throws RunnerException
     */
    private function executePushBuildIn(ICInstruction $instruction): void
    {
        $value = $instruction->getArgs()[0];

        if ($value === 'null') {
            $this->getCurrentScope()->pushStack(null);
        }else if ($value === 'true') {
            $this->getCurrentScope()->pushStack(true);
        }else if ($value === 'false') {
            $this->getCurrentScope()->pushStack(false);
        } else {
            throw new RunnerException('Unknown build-in value: ' . $value, $instruction->getTokenInfo());
        }
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
                if (is_string($left) || is_string($right)) {
                    $this->getCurrentScope()->pushStack($left . $right);
                    return;
                }
                $this->getCurrentScope()->pushStack($left + $right);
                return;
            case '.':
                $this->getCurrentScope()->pushStack($left . $right);
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
            case 'is_not':
                $this->getCurrentScope()->pushStack($left != $right);
                return;
            case 'and':
                $this->getCurrentScope()->pushStack($left && $right);
                return;
            case 'or':
                $this->getCurrentScope()->pushStack($left || $right);
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

        if (array_key_exists($functionName, $this->process->ic->getProcedurePointers())) {
            $this->executeProcedureCall($instruction);
            return true;
        }

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

        $this->getCurrentScope()->returnValue = $value;
        return true;
    }

    private function executeProcedureCall(ICInstruction $instruction): void
    {
        $scope = $this->getCurrentScope();

        $procedureScope = new Scope();
        $procedureScope->returnPointer = $this->process->currentInstructionIndex + 1;

        $this->process->scopes[] = $procedureScope;
        $copyStackValuesAmount = $instruction->getArgs()[1];

        for ($i = 0; $i < $copyStackValuesAmount; $i++) {
            $procedureScope->pushStack($scope->popStack());
        }

        $this->process->currentInstructionIndex = $this->process->ic->getProcedurePointers()[$instruction->getArgs()[0]];
    }

    /**
     * @throws RunnerException
     */
    private function executeObjectFunctionCall(ICInstruction $instruction): bool
    {
        $objectVariable = $instruction->getArgs()[0];
        $object = $this->getVariable($objectVariable);

        $functionName = $instruction->getArgs()[1];
        $provider = null;

        foreach ($this->functionProviders as $providerToCheck) {
            if (false === is_object($object)) {
                continue;
            }

            if ($providerToCheck instanceof ObjectFunctionProviderInterface && $providerToCheck->supports($object, $functionName)) {
                $provider = $providerToCheck;
                break;
            }
        }

        if (!$provider) {
            if (false === is_object($object)) {
                throw new ObjectFunctionNotFoundException('Object not found: ' . $objectVariable, $objectVariable, "", $instruction->getTokenInfo());
            }

            throw new ObjectFunctionNotFoundException('Object function not found: ' . $functionName, $functionName, get_class($object), $instruction->getTokenInfo());
        }

        $numberOfArguments = $instruction->getArgs()[2];
        $arguments = [];
        for ($i = 0; $i < $numberOfArguments; $i++) {
            $arguments[] = $this->getCurrentScope()->popStack();
        }

        $object = $this->getVariable($objectVariable);
        $value = $provider->{$functionName}($this->context, $object, ...$arguments);

        if ($value instanceof Interrupt) {
            $pointer = $instruction->getArgs()[3];
            $this->handleInterrupt($value, $pointer);
            return false;
        }

        $this->getCurrentScope()->returnValue = $value;
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

    private function executeWaitForSignal(ICInstruction $instruction): void
    {
        $waitForAll = $instruction->getArgs()[0];

        $names = array_slice($instruction->getArgs(), 1);

        $this->waitForSignalValue = $names;

        $checkPassed = false;

        if ($waitForAll) {
            $checkPassed = true;
            foreach ($names as $name) {
                if (!in_array($name, $this->signals, true)) {
                    $checkPassed = false;
                    break;
                }
            }
        } else {
            foreach ($names as $name) {
                if (in_array($name, $this->signals, true)) {
                    $checkPassed = true;
                    break;
                }
            }
        }

        if ($checkPassed) {
            $this->process->currentInstructionIndex++;
        } else {
            $this->running = false;
            $this->interruptReason = InterruptReason::WAIT_FOR_SIGNAL;
        }
    }

    /**
     * @throws RunnerException
     */
    private function executeAssertStackCount(ICInstruction $instruction): void
    {
        $expectedCount = $instruction->getArgs()[0];
        $actualCount = count($this->getCurrentScope()->getStack());

        if ($expectedCount !== $actualCount) {
            throw new RunnerException('Expected stack count: ' . $expectedCount . ', actual: ' . $actualCount, $instruction->getTokenInfo());
        }
    }

    private function executeRet(ICInstruction $instruction): void
    {
        $returnExpression = $instruction->getArgs()[0];

        if ($returnExpression !== null) {
            $this->getPreviousScope()->returnValue = $this->getCurrentScope()->popStack();
        }

        if (count($this->process->scopes) === 1) {
            $this->running = false;
            $this->interruptReason = InterruptReason::RETURN;
        } else {
            $pointer = $this->getCurrentScope()->returnPointer;

            $this->process->currentInstructionIndex = $pointer;
            $this->process->scopes = array_slice($this->process->scopes, 0, count($this->process->scopes) - 1);
        }
    }

    /**
     * @throws RunnerException
     */
    private function executeObjectAccess(ICInstruction $instruction): void
    {
        $object = $this->getCurrentScope()->popStack();

        $property = $instruction->getArgs()[0];

        if (false === is_object($object)) {
            throw new RunnerException('Access on non-object', $instruction->getTokenInfo());
        }

        $reflection = new \ReflectionObject($object);

        if ($reflection->hasProperty($property) && $reflection->getProperty($property)->isPublic()) {
            $this->getCurrentScope()->pushStack($object->{$property});
            return;
        }

        if ($reflection->hasMethod($property)) {
            $this->getCurrentScope()->pushStack($object->{$property}());
            return;
        }

        $getMethod = 'get' . ucfirst($property);
        if ($reflection->hasMethod($getMethod)) {
            $this->getCurrentScope()->pushStack($object->{$getMethod}());
            return;
        }

        $isMethod = 'is' . ucfirst($property);
        if ($reflection->hasMethod($isMethod)) {
            $this->getCurrentScope()->pushStack($object->{$isMethod}());
            return;
        }

        $hasMethod = 'has' . ucfirst($property);
        if ($reflection->hasMethod($hasMethod)) {
            $this->getCurrentScope()->pushStack($object->{$hasMethod}());
            return;
        }

        throw new RunnerException('Property not found: ' . $property, $instruction->getTokenInfo());
    }

    public function getCurrentScope(): Scope
    {
        return $this->process->scopes[count($this->process->scopes) - 1];
    }

    public function getPreviousScope(): Scope
    {
        if (count($this->process->scopes) < 2) {
            return $this->getGlobalScope();
        }

        return $this->process->scopes[count($this->process->scopes) - 2];
    }

    public function getGlobalScope(): Scope
    {
        return $this->process->scopes[0];
    }

    /**
     * @throws RunnerException
     */
    public function getVariable(string $variableName): mixed
    {
        $currentScope = $this->getCurrentScope();
        if ($currentScope->hasVariable($variableName)) {
            return $currentScope->getVariable($variableName);
        }

        $rootScope = $this->getGlobalScope();
        if ($rootScope->hasVariable($variableName)) {
            return $rootScope->getVariable($variableName);
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
        $this->process->lastInterruptType = $value->getSignalType();

        if ($value->getSignalType() === InterruptType::AFTER_EXECUTION) {
            $this->process->currentInstructionIndex++;
            $this->getCurrentScope()->pushStack($value->getData());
            $this->interruptData = $value->getExtraData();
            $this->interruptReason = InterruptReason::FUNCTION_REQUEST;
        } else if ($value->getSignalType() === InterruptType::BEFORE_EXECUTION) {
            $this->process->currentInstructionIndex = $beforeArgumentsPointer;
            $this->interruptData = $value->getExtraData();
            $this->interruptReason = InterruptReason::FUNCTION_REQUEST;
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

    public function getReturnValue(): mixed
    {
        return $this->getCurrentScope()->returnValue;
    }

    public function getInterruptData(): mixed
    {
        return $this->interruptData;
    }

    public function reset(): void
    {
        $this->process = new Process();
        $this->running = false;
        $this->interruptData = null;
        $this->signals = [];
        $this->waitForSignalValue = null;
        $this->interruptReason = null;
    }

    public function getWaitForSignalValue(): ?array
    {
        return $this->waitForSignalValue;
    }

    public function setMaxCycles(int $maxCycles): void
    {
        $this->maxCycles = $maxCycles;
    }

    public function getInterruptReason(): ?InterruptReason
    {
        return $this->interruptReason;
    }
}