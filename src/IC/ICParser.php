<?php

namespace Procer\IC;

use Procer\Exception\IcParserException;
use Procer\Parser\Node\AbstractNode;
use Procer\Parser\Node\FunctionCall;
use Procer\Parser\Node\IfNode;
use Procer\Parser\Node\Let;
use Procer\Parser\Node\MathExpression;
use Procer\Parser\Node\MathOperator;
use Procer\Parser\Node\Number;
use Procer\Parser\Node\NumberDecimal;
use Procer\Parser\Node\ObjectFunctionCall;
use Procer\Parser\Node\Reference;
use Procer\Parser\Node\Root;
use Procer\Parser\Node\Stop;
use Procer\Parser\Node\StringNode;

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
            $this->addInstruction(InstructionType::PUSH_VALUE, [$node->value], $node);
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