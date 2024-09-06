<?php

namespace Procer\IC;

use Procer\Exception\IcParserException;
use Procer\Parser\Node\AbstractNode;
use Procer\Parser\Node\ForEachLoop;
use Procer\Parser\Node\FromLoop;
use Procer\Parser\Node\FunctionCall;
use Procer\Parser\Node\IfNode;
use Procer\Parser\Node\Let;
use Procer\Parser\Node\MathExpression;
use Procer\Parser\Node\MathOperator;
use Procer\Parser\Node\Nothing;
use Procer\Parser\Node\Number;
use Procer\Parser\Node\NumberDecimal;
use Procer\Parser\Node\ObjectFunctionCall;
use Procer\Parser\Node\Reference;
use Procer\Parser\Node\Root;
use Procer\Parser\Node\Stop;
use Procer\Parser\Node\StringNode;
use Procer\Parser\Node\WhileLoop;
use Procer\Runner\InternalFunctions;

class ICParser
{
    private array $instructions;

    /**
     * @throws IcParserException
     */
    public function parse(Root $root): IC
    {
        $this->instructions = [];

        $this->processTree($root);

        $this->postProcess();
        return new IC($this->instructions);
    }

    /**
     * @throws IcParserException
     */
    private function processTree(Root $root): void
    {
        $this->parseRoot($root);
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
    private function parseRoot(Root $node): void
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
        } else if ($node instanceof ObjectFunctionCall) {
            $this->resolveObjectFunctionCall($node);
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
    }
}