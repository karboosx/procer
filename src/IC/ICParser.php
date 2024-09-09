<?php

namespace Karboosx\Procer\IC;

use Karboosx\Procer\Exception\IcParserException;
use Karboosx\Procer\Parser\Node\AbstractNode;
use Karboosx\Procer\Parser\Node\ForEachLoop;
use Karboosx\Procer\Parser\Node\FromLoop;
use Karboosx\Procer\Parser\Node\FunctionCall;
use Karboosx\Procer\Parser\Node\IfNode;
use Karboosx\Procer\Parser\Node\Let;
use Karboosx\Procer\Parser\Node\MathExpression;
use Karboosx\Procer\Parser\Node\MathOperator;
use Karboosx\Procer\Parser\Node\Nothing;
use Karboosx\Procer\Parser\Node\Number;
use Karboosx\Procer\Parser\Node\NumberDecimal;
use Karboosx\Procer\Parser\Node\ObjectFunctionCall;
use Karboosx\Procer\Parser\Node\Procedure;
use Karboosx\Procer\Parser\Node\Reference;
use Karboosx\Procer\Parser\Node\ReturnNode;
use Karboosx\Procer\Parser\Node\Root;
use Karboosx\Procer\Parser\Node\Stop;
use Karboosx\Procer\Parser\Node\StringNode;
use Karboosx\Procer\Parser\Node\WaitForSignal;
use Karboosx\Procer\Parser\Node\WhileLoop;
use Karboosx\Procer\Parser\TokenType;
use Karboosx\Procer\Runner\InternalFunctions;

class ICParser
{
    private array $instructions;
    private array $procedures = [];

    /**
     * @throws IcParserException
     */
    public function parse(Root $root): IC
    {
        $this->instructions = [];
        $this->procedures = [];

        $this->resolveRoot($root);

        $this->postProcess();
        return new IC($this->instructions, $this->procedures);
    }

    public function parseExpression(MathExpression $expression): IC
    {
        $this->instructions = [];
        $this->procedures = [];

        $this->resolveMathExpression($expression);

        $this->postProcess();
        return new IC($this->instructions, $this->procedures);
    }

    private function addInstruction(InstructionType $type, array $args = [], ?AbstractNode $node = null): void
    {
        $token = $node?->token;
        if ($token !== null) {
            $tokenInfo = new TokenInfo($token->line, $token->linePosition, strlen($token->value));
        }

        $this->instructions[] = new ICInstruction($type, $args, $tokenInfo ?? null);
    }

    /**
     * @throws IcParserException
     */
    private function resolveRoot(Root $node): void
    {
        foreach ($node->statements as $statement) {
            $this->resolveStatement($statement);
        }
    }

    /**
     * @throws IcParserException
     */
    private function resolveStatement(AbstractNode $node): void
    {
        if ($node instanceof Let) {
            $this->resolveLet($node);
        } else if ($node instanceof FunctionCall) {
            $this->resolveFunctionCall($node);
        } else if ($node instanceof ObjectFunctionCall) {
            $this->resolveObjectFunctionCall($node);
        } else if ($node instanceof IfNode) {
            $this->resolveIf($node);
        } else if ($node instanceof Stop) {
            $this->resolveStop($node);
        } else if ($node instanceof FromLoop) {
            $this->resolveFromLoop($node);
        } else if ($node instanceof ForEachLoop) {
            $this->resolveForEachLoop($node);
        } else if ($node instanceof WhileLoop) {
            $this->resolveWhileLoop($node);
        } else if ($node instanceof Nothing) {
            $this->resolveNothing($node);
        } else if ($node instanceof WaitForSignal) {
            $this->resolveWaitForSignal($node);
        } else if ($node instanceof Procedure) {
            $this->resolveProcedure($node);
        } else if ($node instanceof ReturnNode) {
            $this->resolveReturn($node);
        } else {
            throw new IcParserException('Unknown statement type: ' . $node->token->value, $node->token);
        }
    }

    /**
     * @throws IcParserException
     */
    private function resolveLet(Let $node): void
    {
        $this->resolveMathExpression($node->expression);
        $this->addInstruction(InstructionType::SET_VARIABLE, [$node->variable->value], $node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveFunctionCall(FunctionCall $node): void
    {
        $beforeArgumentsLabel = $this->makeLabel();

        $arguments = array_reverse($node->arguments);
        foreach ($arguments as $argument) {
            $this->resolveMathExpression($argument);
        }

        $this->addInstruction(InstructionType::FUNCTION_CALL, [$node->functionName->value, count($arguments), $beforeArgumentsLabel], $node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveObjectFunctionCall(ObjectFunctionCall $node): void
    {
        $beforeArgumentsLabel = $this->makeLabel();

        $arguments = array_reverse($node->arguments);
        foreach ($arguments as $argument) {
            $this->resolveMathExpression($argument);
        }

        $this->addInstruction(InstructionType::OBJECT_FUNCTION_CALL, [$node->objectName->value, $node->functionName->value, count($arguments), $beforeArgumentsLabel], $node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveIf(IfNode $node, ?Label $conditionFailedLabel = null, ?Label $conditionSuccessfulLabel = null, bool $isMainIf = true): void
    {
        if ($conditionFailedLabel === null) {
            $conditionFailedLabel = $this->makeLabel();
        }

        if ($conditionSuccessfulLabel === null) {
            $conditionSuccessfulLabel = $this->makeLabel();
        }

        if ($node->expression) {
            $this->resolveMathExpression($node->expression);
        }

        $this->addInstruction(InstructionType::IF_NOT_JMP, [$conditionFailedLabel], $node);

        foreach ($node->statements as $statement) {
            $this->resolveStatement($statement);
        }
        $this->addInstruction(InstructionType::JMP, [$conditionSuccessfulLabel], $node);

        $this->setLabelHere($conditionFailedLabel);

        if ($node->or) {
            $finishOrLabel = $this->makeLabel();
            $this->resolveIf($node->or, $finishOrLabel, $conditionSuccessfulLabel, false);
        }

        if ($node->not) {
            foreach ($node->not->statements as $statement) {
                $this->resolveStatement($statement);
            }
            $this->addInstruction(InstructionType::JMP, [$conditionSuccessfulLabel], $node);

        }

        if ($isMainIf) {
            $this->setLabelHere($conditionSuccessfulLabel);
        }
    }

    private function resolveStop(Stop $node): void
    {
        $this->addInstruction(InstructionType::STOP, [], $node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveFromLoop(FromLoop $node): void
    {
        $nameOfTheLoop = '_l/' . count($this->instructions) . '/';
        $nameOfTheAsVariable = $node->asVariable !== null ? $node->asVariable->value : $nameOfTheLoop . 'i';

        $endOfTheLoopLabel = $this->makeLabel();

        if ($node->step !== null) {
            $this->resolveMathExpression($node->step);
        } else {
            $this->addInstruction(InstructionType::PUSH_VALUE, [1], $node);
        }
        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheLoop . 's'], $node);

        $this->resolveMathExpression($node->from);

        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheAsVariable], $node);

        $beginOfTheLoopLabel = $this->makeLabel();
        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheAsVariable], $node);
        $this->resolveMathExpression($node->to);
        $this->addInstruction(InstructionType::MATH_OPERATOR, ['<='], $node);
        $this->addInstruction(InstructionType::IF_NOT_JMP, [$endOfTheLoopLabel], $node);

        foreach ($node->statements as $statement) {
            $this->resolveStatement($statement);
        }

        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheAsVariable], $node);
        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 's'], $node);
        $this->addInstruction(InstructionType::MATH_OPERATOR, ['+'], $node);
        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheAsVariable], $node);

        $this->addInstruction(InstructionType::JMP, [$beginOfTheLoopLabel], $node);

        $this->setLabelHere($endOfTheLoopLabel);
    }

    /**
     * @throws IcParserException
     */
    private function resolveForEachLoop(ForEachLoop $node): void
    {
        $nameOfTheLoop = '_l/' . count($this->instructions) . '/';
        $nameOfTheAsVariable = $node->asVariable->value;

        $endOfTheLoopLabel = $this->makeLabel();

        $this->resolveMathExpression($node->arrayExpression);

        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheLoop . 'a'], $node);

        $this->addInstruction(InstructionType::PUSH_VALUE, [0], $node);
        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheLoop . 'i'], $node);

        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 'a'], $node);
        $this->addInstruction(InstructionType::INTERNAL_FUNCTION_CALL, [InternalFunctions::ARRAY_COUNT_FUNCTION_NAME, 1], $node);
        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheLoop . 'c'], $node);

        $beginOfTheLoopLabel = $this->makeLabel();

        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 'i'], $node);
        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 'c'], $node);
        $this->addInstruction(InstructionType::MATH_OPERATOR, ['<'], $node);
        $this->addInstruction(InstructionType::IF_NOT_JMP, [$endOfTheLoopLabel], $node);

        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 'i'], $node);
        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 'a'], $node);
        $this->addInstruction(InstructionType::INTERNAL_FUNCTION_CALL, [InternalFunctions::ARRAY_GET_FUNCTION_NAME, 2], $node);
        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheAsVariable], $node);

        foreach ($node->statements as $statement) {
            $this->resolveStatement($statement);
        }

        $this->addInstruction(InstructionType::PUSH_VALUE, [1], $node);
        $this->addInstruction(InstructionType::PUSH_VARIABLE, [$nameOfTheLoop . 'i'], $node);
        $this->addInstruction(InstructionType::MATH_OPERATOR, ['+'], $node);
        $this->addInstruction(InstructionType::SET_VARIABLE, [$nameOfTheLoop . 'i'], $node);

        $this->addInstruction(InstructionType::JMP, [$beginOfTheLoopLabel], $node);

        $this->setLabelHere($endOfTheLoopLabel);
    }

    /**
     * @throws IcParserException
     */
    private function resolveWhileLoop(WhileLoop $node): void
    {
        $beginOfTheLoopLabel = $this->makeLabel();
        $endOfTheLoopLabel = $this->makeLabel();

        $this->setLabelHere($beginOfTheLoopLabel);

        $this->resolveMathExpression($node->expression);

        $this->addInstruction(InstructionType::IF_NOT_JMP, [$endOfTheLoopLabel], $node);

        foreach ($node->statements as $statement) {
            $this->resolveStatement($statement);
        }

        $this->addInstruction(InstructionType::JMP, [$beginOfTheLoopLabel], $node);

        $this->setLabelHere($endOfTheLoopLabel);
    }

    private function resolveNothing(Nothing $node): void
    {
        $this->addInstruction(InstructionType::NOP, [], $node);
    }

    private function resolveWaitForSignal(WaitForSignal $node): void
    {
        $this->addInstruction(InstructionType::WAIT_FOR_SIGNAL, [$node->signalName->value], $node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveProcedure(Procedure $node): void
    {
        $endOfTheProcedureLabel = $this->makeLabel();

        $this->addInstruction(InstructionType::JMP, [$endOfTheProcedureLabel], $node);
        $procedureLabel = $this->makeLabel();
        $this->procedures[$node->procedureName->value] = $procedureLabel;

        $this->addInstruction(InstructionType::ASSERT_STACK_COUNT, [count($node->arguments)], $node);

        $arguments = array_reverse($node->arguments);
        foreach ($arguments as $argument) {
            $this->addInstruction(InstructionType::SET_VARIABLE, [$argument->value], $node);
        }

        foreach ($node->statements as $statement) {
            $this->resolveStatement($statement);
        }

        $this->addInstruction(InstructionType::RET, [], $node);

        $this->setLabelHere($endOfTheProcedureLabel);

    }

    /**
     * @throws IcParserException
     */
    private function resolveReturn(ReturnNode $node): void
    {
        if ($node->expression) {
            $this->resolveMathExpression($node->expression);
        }

        $this->addInstruction(InstructionType::RET, [($node->expression !== null)], $node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveMathExpression(MathExpression $node): void
    {
        $this->resolveMathOperation($node->node);
    }

    /**
     * @throws IcParserException
     */
    private function resolveMathOperation(AbstractNode $node): void
    {
        if ($node instanceof MathOperator) {
            if ($node->left->token->type === TokenType::IDENTIFIER && $node->left->token->value === 'signal') {
                $this->resolveSignalComparison($node);
                return;
            }

            $this->resolveMathOperation($node->left);
            $this->resolveMathOperation($node->right);
            $this->addInstruction(InstructionType::MATH_OPERATOR, [$node->operator->value], $node);
        } else {
            $this->resolveValue($node);
        }
    }

    /**
     * @throws IcParserException
     */
    private function resolveSignalComparison(MathOperator $node): void
    {
        if ($node->operator->value === IfNode::IS_OPERATOR) {
            $this->addInstruction(InstructionType::PUSH_VALUE, [$node->right->token->value], $node);
            $this->addInstruction(InstructionType::INTERNAL_FUNCTION_CALL, [InternalFunctions::SIGNAL_EXIST, 1], $node);
        } else if ($node->operator->value === IfNode::IS_NOT_OPERATOR) {
            $this->addInstruction(InstructionType::PUSH_VALUE, [$node->right->token->value], $node);
            $this->addInstruction(InstructionType::INTERNAL_FUNCTION_CALL, [InternalFunctions::SIGNAL_NOT_EXIST, 1], $node);
        } else {
            throw new IcParserException('Unknown signal operator: ' . $node->operator->value, $node->token);
        }
    }

    /**
     * @throws IcParserException
     */
    private function resolveValue(AbstractNode $node): void
    {
        if ($node instanceof Number) {
            $this->addInstruction(InstructionType::PUSH_VALUE, [(int)$node->value], $node);
        } else if ($node instanceof NumberDecimal) {
            $this->addInstruction(InstructionType::PUSH_VALUE, [(float)$node->value], $node);
        } else if ($node instanceof StringNode) {
            $this->addInstruction(InstructionType::PUSH_VALUE, [$node->value], $node);
        } else if ($node instanceof Reference) {
            $this->addInstruction(InstructionType::PUSH_VARIABLE, [$node->value], $node);
        } else if ($node instanceof FunctionCall) {
            $this->resolveFunctionCall($node);
            $this->addInstruction(InstructionType::PUSH_FUNCTION_RESULT, [], $node);
        } else if ($node instanceof ObjectFunctionCall) {
            $this->resolveObjectFunctionCall($node);
            $this->addInstruction(InstructionType::PUSH_FUNCTION_RESULT, [], $node);
        } else if ($node instanceof MathExpression) {
            $this->resolveMathExpression($node);
        } else {
            throw new IcParserException('Unknown node type: ' . $node->token->value, $node->token);
        }
    }

    private function makeLabel(): Label
    {
        return new Label(count($this->instructions));
    }

    private function setLabelHere(Label $label): void
    {
        $label->pointer = count($this->instructions);
    }

    private function postProcess(): void
    {
        /** @var ICInstruction $instruction */
        foreach ($this->instructions as $instruction) {
            foreach ($instruction->getArgs() as $key => $arg) {
                if ($arg instanceof Label) {
                    $instruction->setArg($key, $arg->pointer);
                }
            }
        }

        foreach ($this->procedures as $procedureName => $label) {
            $this->procedures[$procedureName] = $label->pointer;
        }
    }

}