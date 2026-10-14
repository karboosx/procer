<?php

namespace Karboosx\Procer\Runner;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\FunctionNotFoundException;
use Karboosx\Procer\Exception\MaxCyclesException;
use Karboosx\Procer\Exception\ObjectFunctionNotFoundException;
use Karboosx\Procer\Exception\PropertyNotFoundException;
use Karboosx\Procer\Exception\RunnerException;
use Karboosx\Procer\Exception\VariableNotFoundException;
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
use Karboosx\Procer\Serializer\LazyObject;

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
        $this->assertNotRunning();
        if (!isset($this->process->ic)) {
            throw new RunnerException('No program loaded; call run() before resume()');
        }
        $this->context = new Context($this);

        $this->running = true;
        $this->interruptReason = null;
        $this->interruptData = null;

        $amountOfInstructions = count($this->process->ic->getInstructions());

        while ($this->process->currentInstructionIndex < $amountOfInstructions && $this->running) {
            $instruction = $this->process->ic->getInstruction($this->process->currentInstructionIndex);
            if ($this->maxCycles >= 0 && $this->process->cycles >= $this->maxCycles) {
                $this->running = false;
                throw new MaxCyclesException(
                    "Maximum cycle limit ({$this->maxCycles}) exceeded. The script may contain an infinite loop. Use setMaxCycles() to raise the limit if needed.",
                    $instruction->getTokenInfo()
                );
            }
            try {
                $this->executeInstruction($instruction);
            } catch (\Throwable $error) {
                $this->running = false;
                throw $error;
            }
            $this->process->cycles++;
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

        return $this->isFinished() ? $this->getCurrentScope()->popStack() : null;
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
            case InstructionType::SET_LOCAL_VARIABLE:
                $this->executeSetLocalVariable($instruction);
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

        if ($this->getCurrentScope() !== $this->getGlobalScope() && $this->getCurrentScope()->hasVariable($variableName)) {
            $this->getCurrentScope()->setVariable($variableName, $value);
            return;
        }

        if ($this->getGlobalScope()->hasVariable($variableName)) {
            $this->getGlobalScope()->setVariable($variableName, $value);
            return;
        }

        $this->getCurrentScope()->setVariable($variableName, $value);
    }

    private function executeSetLocalVariable(ICInstruction $instruction): void
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
        $value = $this->calculateMathResult($instruction);
        $this->getCurrentScope()->discardStack(2);
        $this->getCurrentScope()->pushStack($value);
    }

    private function calculateMathResult(ICInstruction $instruction): mixed
    {
        $operator = $instruction->getArgs()[0];
        $right = $this->getCurrentScope()->peekStack();
        $left = $this->getCurrentScope()->peekStack(1);

        $concatenating = $operator === '.' || ($operator === '+' && (is_string($left) || is_string($right)));
        if ($concatenating) {
            foreach ([$left, $right] as $value) {
                if ($value !== null && !is_scalar($value) && !$value instanceof \Stringable) {
                    throw new RunnerException('Cannot concatenate ' . get_debug_type($value), $instruction->getTokenInfo());
                }
            }
        } elseif (in_array($operator, ['+', '-', '*', '/', '%'], true)) {
            foreach ([$left, $right] as $value) {
                if ((!is_numeric($value) && !is_bool($value) && $value !== null) || (is_numeric($value) && !is_finite((float)$value))) {
                    throw new RunnerException("Operator '{$operator}' requires finite numeric operands", $instruction->getTokenInfo());
                }
            }
        }

        switch ($operator) {
            case '+':
                if (is_string($left) || is_string($right)) {
                    return $left . $right;
                }
                return $this->finiteArithmeticResult($left + $right, $instruction);
            case '.':
                return $left . $right;
            case '-':
                return $this->finiteArithmeticResult($left - $right, $instruction);
            case '*':
                return $this->finiteArithmeticResult($left * $right, $instruction);
            case '/':
                if ($right == 0) {
                    throw new RunnerException("Division by zero: cannot divide {$left} by 0", $instruction->getTokenInfo());
                }
                return $this->finiteArithmeticResult($left / $right, $instruction);
            case '%':
                foreach ([$left, $right] as $value) {
                    if (!is_int($value) && ((float)$value >= (float)PHP_INT_MAX || (float)$value < (float)PHP_INT_MIN)) {
                        throw new RunnerException('Modulo operand is outside the integer range', $instruction->getTokenInfo());
                    }
                }
                $left = (int)$left;
                $right = (int)$right;
                if ($right === 0) {
                    throw new RunnerException("Division by zero: cannot compute {$left} modulo 0", $instruction->getTokenInfo());
                }
                return $left % $right;
            case '>':
                return $left > $right;
            case '<':
                return $left < $right;
            case '>=':
                return $left >= $right;
            case '<=':
                return $left <= $right;
            case '=':
            case 'is':
                return $left == $right;
            case '!=':
            case 'is_not':
                return $left != $right;
            case 'and':
                return $left && $right;
            case 'or':
                return $left || $right;
        }

        throw new RunnerException('Unknown math operator: ' . $operator, $instruction->getTokenInfo());
    }

    private function finiteArithmeticResult(int|float $value, ICInstruction $instruction): int|float
    {
        if (is_float($value) && !is_finite($value)) {
            throw new RunnerException('Arithmetic result is not finite', $instruction->getTokenInfo());
        }
        return $value;
    }

    /**
     * @throws RunnerException
     */
    private function executeFunctionCall(ICInstruction $instruction): bool
    {
        $functionName = $instruction->getArgs()[0];

        if (array_key_exists($functionName, $this->process->ic->getProcedurePointers())) {
            $this->executeProcedureCall($instruction);
            return false;
        }

        $provider = null;

        foreach ($this->functionProviders as $providerToCheck) {
            if ($providerToCheck instanceof FunctionProviderInterface && $providerToCheck->supports($functionName)) {
                $provider = $providerToCheck;
                break;
            }
        }

        if (!$provider) {
            throw new FunctionNotFoundException(
                "Function '{$functionName}' is not defined. Make sure a FunctionProviderInterface that supports it is registered.",
                $functionName,
                $instruction->getTokenInfo()
            );
        }

        $numberOfArguments = $instruction->getArgs()[1];
        $arguments = $this->getStackArguments($numberOfArguments);

        $value = $provider->{$functionName}($this->context, ...$arguments);
        $this->getCurrentScope()->discardStack($numberOfArguments);
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
        if (count($this->process->scopes) >= 256) {
            throw new RunnerException('Maximum procedure call depth exceeded', $instruction->getTokenInfo());
        }
        $scope = $this->getCurrentScope();
        $copyStackValuesAmount = $instruction->getArgs()[1];
        $arguments = $this->getStackArguments($copyStackValuesAmount);

        $procedureScope = new Scope();
        $procedureScope->returnPointer = $this->process->currentInstructionIndex + 1;

        $this->process->scopes[] = $procedureScope;
        foreach ($arguments as $argument) {
            $procedureScope->pushStack($argument);
        }
        $scope->discardStack($copyStackValuesAmount);

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
                $actualType = gettype($object);
                throw new ObjectFunctionNotFoundException(
                    "Cannot call '{$functionName}' on variable '{$objectVariable}': expected an object, got {$actualType}",
                    $functionName,
                    "",
                    $instruction->getTokenInfo()
                );
            }

            $class = get_class($object);
            throw new ObjectFunctionNotFoundException(
                "Method '{$functionName}' not found for object of type '{$class}'. Make sure an ObjectFunctionProviderInterface that supports it is registered.",
                $functionName,
                $class,
                $instruction->getTokenInfo()
            );
        }

        $numberOfArguments = $instruction->getArgs()[2];
        $arguments = $this->getStackArguments($numberOfArguments);

        $value = $provider->{$functionName}($this->context, $object, ...$arguments);
        $this->getCurrentScope()->discardStack($numberOfArguments);

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
        $arguments = $this->getStackArguments($numberOfArguments);

        try {
            $value = $this->internalFunctions->{$functionName}($this->context, ...$arguments);
        } catch (\TypeError|RunnerException $error) {
            throw new RunnerException($error->getMessage(), $instruction->getTokenInfo());
        }
        $this->getCurrentScope()->discardStack($numberOfArguments);
        $this->getCurrentScope()->pushStack($value);
    }

    private function getStackArguments(int $count): array
    {
        $arguments = [];
        for ($i = 0; $i < $count; $i++) {
            $arguments[] = $this->getCurrentScope()->peekStack($i);
        }
        return $arguments;
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
        $returnValue = null;

        if ($returnExpression) {
            $returnValue = $this->getCurrentScope()->popStack();
        }

        if (count($this->process->scopes) === 1) {
            $this->getCurrentScope()->returnValue = $returnValue;
            $this->getCurrentScope()->externalReturnValue = $returnValue;
            $this->process->currentInstructionIndex = count($this->process->ic->getInstructions());
            $this->running = false;
            $this->interruptReason = InterruptReason::RETURN;
        } else {
            $pointer = $this->getCurrentScope()->returnPointer;

            $this->getPreviousScope()->returnValue = $returnValue;
            $this->process->currentInstructionIndex = $pointer;
            $this->process->scopes = array_slice($this->process->scopes, 0, count($this->process->scopes) - 1);
        }
    }

    /**
     * @throws RunnerException
     */
    private function executeObjectAccess(ICInstruction $instruction): void
    {
        $value = $this->readObjectProperty($instruction);
        $this->getCurrentScope()->discardStack(1);
        $this->getCurrentScope()->pushStack($value);
    }

    private function readObjectProperty(ICInstruction $instruction): mixed
    {
        $object = $this->getCurrentScope()->peekStack();

        $property = $instruction->getArgs()[0];

        if (false === is_object($object)) {
            $actualType = gettype($object);
            throw new RunnerException(
                "Cannot access property '{$property}' on a non-object value (got {$actualType})",
                $instruction->getTokenInfo()
            );
        }

        $reflection = new \ReflectionObject($object);

        if ($reflection->hasProperty($property) && $reflection->getProperty($property)->isPublic()) {
            if (!$reflection->getProperty($property)->isInitialized($object)) {
                throw new RunnerException("Property '{$property}' is uninitialized", $instruction->getTokenInfo());
            }
            return $reflection->getProperty($property)->getValue($object);
        }

        if ($reflection->hasMethod($property) && $this->isPublicZeroArgumentMethod($reflection->getMethod($property))) {
            return $object->{$property}();
        }

        $getMethod = 'get' . ucfirst($property);
        if ($reflection->hasMethod($getMethod) && $this->isPublicZeroArgumentMethod($reflection->getMethod($getMethod))) {
            return $object->{$getMethod}();
        }

        $isMethod = 'is' . ucfirst($property);
        if ($reflection->hasMethod($isMethod) && $this->isPublicZeroArgumentMethod($reflection->getMethod($isMethod))) {
            return $object->{$isMethod}();
        }

        $hasMethod = 'has' . ucfirst($property);
        if ($reflection->hasMethod($hasMethod) && $this->isPublicZeroArgumentMethod($reflection->getMethod($hasMethod))) {
            return $object->{$hasMethod}();
        }

        throw new PropertyNotFoundException($property, get_class($object), $instruction->getTokenInfo());
    }

    private function isPublicZeroArgumentMethod(\ReflectionMethod $method): bool
    {
        return $method->isPublic() && $method->getNumberOfRequiredParameters() === 0;
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

        throw new VariableNotFoundException($variableName, $this->getCurrentTokenInfo());
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
        return $this->process->currentInstructionIndex >= count($this->process->ic->getInstructions()) || $this->interruptReason == InterruptReason::RETURN;
    }

    /** @noinspection PhpUnused */
    public function isRunning(): bool
    {
        return $this->running;
    }

    public function assertNotRunning(): void
    {
        if ($this->running) {
            throw new RunnerException('This interpreter is already running; use a separate Procer instance for nested execution');
        }
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
            $this->getCurrentScope()->returnValue = $value->getData();
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
        return ($this->process->ic->getInstructions()[$this->process->currentInstructionIndex] ?? null)?->getTokenInfo();
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
        $scope = $this->getCurrentScope();
        if ($scope->returnValue instanceof LazyObject) {
            $scope->returnValue = $scope->returnValue->getRealObject();
        }
        return $scope->returnValue;
    }

    public function getExternalReturnValue(): mixed
    {
        $scope = $this->getCurrentScope();
        if ($scope->externalReturnValue instanceof LazyObject) {
            $scope->externalReturnValue = $scope->externalReturnValue->getRealObject();
        }
        return $scope->externalReturnValue;
    }

    public function getInterruptData(): mixed
    {
        return $this->interruptData;
    }

    public function reset(): void
    {
        $this->assertNotRunning();
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
