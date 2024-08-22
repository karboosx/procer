<?php

namespace Procer\Parser;

use Procer\Exception\ParserException;
use Procer\Parser\Node\{AbstractNode,
    FunctionCall,
    IfNode,
    Let,
    MathExpression,
    MathOperator,
    Number,
    NumberDecimal,
    ObjectFunctionCall,
    Reference,
    Root,
    Stop,
    StringNode};

class Parser
{
    private const PRECEDENCE = [
        TokenType::PLUS->name => 1,
        TokenType::MINUS->name => 1,
        TokenType::MULTIPLY->name => 2,
        TokenType::DIVIDE->name => 2,
        TokenType::MODULO->name => 2,
        TokenType::EQUALS->name => 0,
        TokenType::NOT_EQUALS->name => 0,
        TokenType::LESS_THEN->name => 0,
        TokenType::LESS_OR_EQUALS->name => 0,
        TokenType::MORE_THEN->name => 0,
        TokenType::MORE_OR_EQUALS->name => 0,
        IfNode::IS_OPERATOR => 0,
    ];

    private const TOKEN_MATH_OPERATORS = [
        TokenType::PLUS,
        TokenType::MINUS,
        TokenType::MULTIPLY,
        TokenType::DIVIDE,
        TokenType::MODULO,
        TokenType::EQUALS,
        TokenType::NOT_EQUALS,
        TokenType::LESS_THEN,
        TokenType::LESS_OR_EQUALS,
        TokenType::MORE_THEN,
        TokenType::MORE_OR_EQUALS,
    ];

    private const IDENTIFIER_MATH_OPERATORS = [
        IfNode::IS_OPERATOR,
    ];

    /**
     * @var Token[]
     */
    private array $tokens;
    private int $currentTokenIndex;
    private int $amountOfTokens;


    public function __construct(
        private readonly Tokenizer $tokenizer
    )
    {
    }

    /**
     * @throws ParserException
     */
    public function parse(string $code): Root
    {
        try {
            $this->tokens = $this->tokenizer->tokenize($code);

            $this->currentTokenIndex = 0;
            $this->amountOfTokens = count($this->tokens);

            $rootStatements = [];

            while ($this->currentTokenIndex < $this->amountOfTokens) {
                $rootStatements[] = $this->parseStatement();
            }

            $root = new Root();
            $root->statements = $rootStatements;

            return $root;
        } catch (ParserException $e) {
            $lines = explode("\n", $code);
            if ($e->getToken() !== null) {
                $e->setCodeLine($lines[$e->getToken()->getLine() - 1]);
            }

            throw $e;
        }
    }


    /**
     * @throws ParserException
     */
    private function parseStatement(): AbstractNode
    {
        if ($this->matchValue(TokenType::IDENTIFIER, IfNode::IF_KEYWORD)) {
            // if statement
            // if <expression> do [<statement>, ...] done
            // if <expression> do [<statement>, ...] or <expression> do [<statement>, ...] done
            // if <expression> do [<statement>, ...] if not do [<statement>, ...] done
            $ifToken = $this->expectValue(TokenType::IDENTIFIER, IfNode::IF_KEYWORD);
            return $this->parseIf($ifToken);
        }

        if ($this->matchValue(TokenType::IDENTIFIER, Stop::STOP_KEYWORD)) {
            // stop statement
            // stop.
            $stopToken = $this->expectValue(TokenType::IDENTIFIER, Stop::STOP_KEYWORD);
            $node = new Stop();
            $node->token = $stopToken;
            $this->expect(TokenType::DOT);
            return $node;
        }

        if ($this->match(TokenType::IDENTIFIER) && $this->match(TokenType::LEFT_PARENTHESIS, 1)) {
            // function call
            // <IDENTIFIER>([<TERM>, ...])
            $functionNameToken = $this->expect(TokenType::IDENTIFIER);
            $node = $this->parseFunctionCall($functionNameToken);
            $this->expect(TokenType::DOT);
            return $node;
        }

        if ($this->matchValue(TokenType::IDENTIFIER, ObjectFunctionCall::ON_KEYWORD)) {
            // object function call
            // on <IDENTIFIER> <IDENTIFIER>([<TERM>, ...])
            // on <IDENTIFIER> <IDENTIFIER>
            // on <IDENTIFIER> <ANY_VERB> <IDENTIFIER>([<TERM>, ...])
            // on <IDENTIFIER> <ANY_VERB> <IDENTIFIER>
            $onToken = $this->expect(TokenType::IDENTIFIER);
            $node = $this->parseObjectFunctionCall($onToken);
            $this->expect(TokenType::DOT);
            return $node;
        }

        if ($this->matchValue(TokenType::IDENTIFIER, Let::LET_KEYWORD)) {
            // property assignment
            // let <IDENTIFIER> = <TERM>
            $nameToken = $this->expect(TokenType::IDENTIFIER);
            $node = $this->parseLetProperty($nameToken);
            $this->expect(TokenType::DOT);
            return $node;
        }

        $this->throwUnexpectedToken('WRONG_STMT');
    }


    /**
     * @param Token $letToken
     * @return Let
     * @throws ParserException
     */
    private function parseLetProperty(Token $letToken): Let
    {
        $variableToken = $this->expect(TokenType::IDENTIFIER);

        $this->expectValue(TokenType::IDENTIFIER, Let::BE_KEYWORD);

        $node = new Let(new TokenValue($variableToken, $variableToken->value), $this->parseMathExpression());
        $node->token = $letToken;

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseFunctionCall(Token $functionNameToken): FunctionCall
    {
        $node = new FunctionCall;
        $node->functionName = new TokenValue($functionNameToken, $functionNameToken->value);
        $node->token = $functionNameToken;

        $this->expect(TokenType::LEFT_PARENTHESIS);

        while (!$this->match(TokenType::RIGHT_PARENTHESIS)) {
            $property = $this->parseMathExpression();
            $node->arguments[] = $property;

            if ($this->match(TokenType::COMMA)) {
                $this->consume();
            }
        }

        $this->expect(TokenType::RIGHT_PARENTHESIS);

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseObjectFunctionCall(Token $onToken): ObjectFunctionCall
    {
        if (
            $this->match(TokenType::IDENTIFIER)
            && $this->match(TokenType::IDENTIFIER, 1)
            && $this->match(TokenType::IDENTIFIER, 2)
            && $this->match(TokenType::LEFT_PARENTHESIS, 3)
        ) {
            // object function call with optional verb
            // on <IDENTIFIER> <ANY_VERB> <IDENTIFIER>([<TERM>, ...])
            return $this->parseObjectFunctionCallByControl($onToken, true, true);
        } else if (
            $this->match(TokenType::IDENTIFIER)
            && $this->match(TokenType::IDENTIFIER, 1)
            && $this->match(TokenType::LEFT_PARENTHESIS, 2)
        ) {
            // object function call
            // on <IDENTIFIER> <IDENTIFIER>([<TERM>, ...])
            return $this->parseObjectFunctionCallByControl($onToken, false, true);
        } else if (
            $this->match(TokenType::IDENTIFIER)
            && $this->match(TokenType::IDENTIFIER, 1)
            && $this->match(TokenType::IDENTIFIER, 2)
            && !$this->match(TokenType::LEFT_PARENTHESIS, 3)
        ) {
            // object function call with optional verb without parenthesis
            // on <IDENTIFIER> <ANY_VERB> <IDENTIFIER>
            return $this->parseObjectFunctionCallByControl($onToken, true, false);
        } else if (
            $this->match(TokenType::IDENTIFIER)
            && $this->match(TokenType::IDENTIFIER, 1)
            && !$this->match(TokenType::LEFT_PARENTHESIS, 2)
        ) {
            // object function call without parenthesis
            // on <IDENTIFIER> <IDENTIFIER>
            return $this->parseObjectFunctionCallByControl($onToken, false, false);
        }

        $this->throwUnexpectedToken('OBJECT_FUNCTION_CALL');
    }

    /**
     * @throws ParserException
     */
    private function parseObjectFunctionCallByControl(Token $onToken, bool $parseOptionalVerb, $parseArguments): ObjectFunctionCall
    {
        $node = new ObjectFunctionCall;
        $node->token = $onToken;

        $objectNameToken = $this->expect(TokenType::IDENTIFIER);

        if ($parseOptionalVerb) {
            $this->expect(TokenType::IDENTIFIER);
        }

        $functionNameToken = $this->expect(TokenType::IDENTIFIER);

        $node->objectName = new TokenValue($objectNameToken, $objectNameToken->value);
        $node->functionName = new TokenValue($functionNameToken, $functionNameToken->value);

        if ($parseArguments) {
            $this->expect(TokenType::LEFT_PARENTHESIS);

            while (!$this->match(TokenType::RIGHT_PARENTHESIS)) {
                $property = $this->parseMathExpression();
                $node->arguments[] = $property;

                if ($this->match(TokenType::COMMA)) {
                    $this->consume();
                }
            }

            $this->expect(TokenType::RIGHT_PARENTHESIS);
        }

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseIf(Token $ifToken, bool $parseExpression = true, bool $allowOr = true, bool $allowNot = true): IfNode
    {
        $node = new IfNode;
        $node->token = $ifToken;

        if ($parseExpression) {
            $expression = $this->parseMathExpression();
            $node->expression = $expression;
        }

        $this->expectValue(TokenType::IDENTIFIER, IfNode::DO_KEYWORD);

        while (
            !$this->matchValue(TokenType::IDENTIFIER, IfNode::DONE_KEYWORD)
            && !$this->matchValue(TokenType::IDENTIFIER, IfNode::OR_KEYWORD)
            && !(
                $this->matchValue(TokenType::IDENTIFIER, IfNode::IF_KEYWORD)
                && $this->matchValue(TokenType::IDENTIFIER, IfNode::NOT_KEYWORD, 1)
                && $this->matchValue(TokenType::IDENTIFIER, IfNode::DO_KEYWORD, 2)
            )
        ) {
            $node->statements[] = $this->parseStatement();
        }

        if ($this->matchValue(TokenType::IDENTIFIER, IfNode::OR_KEYWORD) && $allowOr) {
            $orToken = $this->expectValue(TokenType::IDENTIFIER, IfNode::OR_KEYWORD);
            $node->or = $this->parseIf($orToken);
        } else if (
            $this->matchValue(TokenType::IDENTIFIER, IfNode::IF_KEYWORD)
            && $this->matchValue(TokenType::IDENTIFIER, IfNode::NOT_KEYWORD, 1)
            && $this->matchValue(TokenType::IDENTIFIER, IfNode::DO_KEYWORD, 2)
            && $allowNot
        ) {
            $ifToken = $this->expectValue(TokenType::IDENTIFIER, IfNode::IF_KEYWORD);
            $this->expectValue(TokenType::IDENTIFIER, IfNode::NOT_KEYWORD);
            $node->not = $this->parseIf($ifToken, false, false, false);
        } else {
            $this->expectValue(TokenType::IDENTIFIER, IfNode::DONE_KEYWORD);
        }

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function expect(TokenType $type): Token
    {
        if ($this->match($type)) {
            return $this->consume();
        }

        $this->throwUnexpectedToken('EXP_TYPE_NOT_MATCH');
    }

    /**
     * @throws ParserException
     */
    private function expectValue(TokenType $type, string $value, bool $lower = true): Token
    {
        if ($this->match($type)) {
            $token = $this->consume();

            if ($lower) {
                if (strtolower($token->value) === $value) {
                    return $token;
                }
            } else if ($token->value === $value) {
                return $token;
            }

            $this->throwUnexpectedToken('EXPV_VALUE_NOT_MATCH');
        }

        $this->throwUnexpectedToken('EXPV_TYPE_NOT_MATCH');
    }

    private function match(TokenType $type, int $lookahead = 0): bool
    {
        $token = $this->peek($lookahead);

        if ($token === null) {
            return false;
        }

        return $token->is($type);
    }

    private function matchValue(TokenType $type, string $value, int $lookahead = 0): bool
    {
        return $this->match($type, $lookahead) && $this->peekValue($lookahead) === $value;
    }

    private function matchOneOf(array $types, int $lookahead = 0): bool
    {
        $token = $this->peek($lookahead);

        if ($token === null) {
            return false;
        }

        foreach ($types as $type) {
            if ($token->is($type)) {
                return true;
            }
        }

        return false;
    }

    private function matchOneOfValue(TokenType $type, array $list, int $lookahead = 0): bool
    {
        $token = $this->peek($lookahead);

        if ($token === null) {
            return false;
        }

        foreach ($list as $item) {
            if ($this->matchValue($type, $item, $lookahead)) {
                return true;
            }
        }

        return false;
    }

    private function peek(int $lookahead = 0): ?Token
    {
        if ($this->currentTokenIndex + $lookahead >= $this->amountOfTokens) {
            return null;
        }

        return $this->tokens[$this->currentTokenIndex + $lookahead];
    }

    private function peekValue(int $lookahead = 0): ?string
    {
        return $this->peek($lookahead)?->value;
    }

    private function peekValueLower(int $lookahead = 0): ?string
    {
        $string = $this->peekValue($lookahead);

        if ($string === null) {
            return null;
        }

        return strtolower($string);
    }

    /**
     * @throws ParserException
     */
    private function consume(): Token
    {
        if ($this->currentTokenIndex >= $this->amountOfTokens) {
            $this->throwUnexpectedToken('OUT_OF_BOUNDS');
        }

        return $this->tokens[$this->currentTokenIndex++];
    }

    /**
     * @throws ParserException
     */
    private function parseSingleTermToken(): AbstractNode
    {
        if ($this->match(TokenType::STRING)) {
            $token = $this->consume();
            $stringNode = new StringNode($this->parseString($token->value));
            $stringNode->token = $token;
            return $stringNode;
        } else if ($this->match(TokenType::NUMBER)) {
            $token = $this->consume();
            $number = new Number($token->value);
            $number->token = $token;
            return $number;
        } else if ($this->match(TokenType::NUMBER_DECIMAL)) {
            $token = $this->consume();
            $numberDecimal = new NumberDecimal($token->value);
            $numberDecimal->token = $token;
            return $numberDecimal;
        } else if ($this->match(TokenType::IDENTIFIER) && $this->match(TokenType::LEFT_PARENTHESIS, 1)) {
            $token = $this->expect(TokenType::IDENTIFIER);
            return $this->parseFunctionCall($token);
        } else if ($this->match(TokenType::IDENTIFIER) && $this->peekValueLower() === ObjectFunctionCall::ON_KEYWORD) {
            $onToken = $this->expect(TokenType::IDENTIFIER);
            return $this->parseObjectFunctionCall($onToken);
        } else if ($this->match(TokenType::IDENTIFIER)) {
            $token = $this->consume();
            $reference = new Reference($token->value);
            $reference->token = $token;
            return $reference;
        } else if ($this->match(TokenType::LEFT_PARENTHESIS)) {
            $this->consume();
            $node = $this->parseMathExpression();
            $this->expect(TokenType::RIGHT_PARENTHESIS);
            return $node;
        } else {
            $this->throwUnexpectedToken('WRONG_TERM');
        }
    }

    /**
     * @throws ParserException
     */
    private function parseMathExpression(): MathExpression
    {
        // Shunting-yard algorithm
        $output = [];
        $operators = [];

        $left = $this->parseSingleTermToken();
        $output[] = $left;

        while ($this->matchConditionOperator()) {
            $operator = $this->consume();
            $right = $this->parseSingleTermToken();

            while (!empty($operators) && $this->getPrecedence($operators[count($operators) - 1]) >= $this->getPrecedence($operator)) {
                $token = array_pop($operators);
                $mathOperator = new MathOperator(new TokenValue($token, $token->value));
                $mathOperator->token = $token;
                $output[] = $mathOperator;
            }

            $operators[] = $operator;
            $output[] = $right;
        }

        while (!empty($operators)) {
            $token = array_pop($operators);
            $mathOperator = new MathOperator(new TokenValue($token, $token->value));
            $mathOperator->token = $token;
            $output[] = $mathOperator;
        }

        // build AST
        $root = new MathExpression();
        $stack = [];

        foreach ($output as $node) {
            if ($node instanceof MathOperator) {
                $right = array_pop($stack);
                $left = array_pop($stack);

                $node->left = $left;
                $node->right = $right;
            }

            $stack[] = $node;
        }

        $array_pop = array_pop($stack);
        $root->node = $array_pop;
        $root->token = $array_pop->token;

        return $root;
    }

//    private function parseParenthesesExpression(): ASTNode
//    {
//        $this->expect(TokenType::LEFT_PARENTHESIS);
//        $node = $this->parseMathExpression();
//        $this->expect(TokenType::RIGHT_PARENTHESIS);
//        return $node;
//    }

    private function getPrecedence(Token $operator): int
    {
        return self::PRECEDENCE[$operator->type->value];
    }
//
//    private function explodeMathExpression(ASTNode $left): ASTNode
//    {
//        if (count($left->children) !== 1) {
//            throw new ParserException('Expected exactly one child node', $left->token);
//        }
//
//        return $left->children[0];
//    }
//
//    /**
//     * @throws ParserException
//     */
//    private function parseObjectProperty(Token $nameToken): ASTNode
//    {
//        $node = new ASTNode(ASTType::PROPERTY, $nameToken, $nameToken->value);
//        $objectNode = new ASTNode(ASTType::OBJECT, $nameToken, $nameToken->value);
//
//        $child = $this->parseObject($objectNode);
//        $node->addChild($child);
//
//        return $node;
//    }
//
//    /**
//     * @throws ParserException
//     */
//    private function parseObjectArgument(Token $nameToken): ASTNode
//    {
//        $argument = new ASTNode(ASTType::ARGUMENT, $nameToken, $nameToken->value);
//        $node = new ASTNode(ASTType::OBJECT, $nameToken, $nameToken->value);
//
//        $this->parseObject($node);
//        $argument->addChild($node);
//
//        return $argument;
//    }

    private function parseString(string $value): string
    {
        return substr($value, 1, -1);
    }

    /**
     * @throws ParserException
     */
    private function throwUnexpectedToken(string $id = '100')
    {
        $unexpectedToken = $this->peek();
        if ($unexpectedToken === null) {
            throw new ParserException('[' . $id . '] Unexpected end of input');
        }

        throw new ParserException('[' . $id . '] Unexpected token of type ' . $unexpectedToken->getType()->value, $unexpectedToken);
    }
//
//    /**
//     * @throws ParserException
//     */
//    private function parseAssert(Token $nameToken, string $type): ASTNode
//    {
//        $node = new ASTNode(ASTType::ASSERT, $nameToken, $type);
//
//        $childValue = $this->expect(TokenType::IDENTIFIER);
//        $child = new ASTNode(ASTType::REFERENCE, $childValue, $childValue->value);
//
//        $node->addChild($child);
//
//        return $node;
//    }
//
//    /**
//     * @throws ParserException
//     */
//    private function parseReturn(Token $nameToken): ASTNode
//    {
//        $node = new ASTNode(ASTType::RETURN, $nameToken, 'return');
//
//        $child = $this->parseTerm();
//        $node->addChild($child);
//
//        return $node;
//    }
    /**
     * @return bool
     */
    public function matchConditionOperator(): bool
    {
        return $this->matchOneOf(self::TOKEN_MATH_OPERATORS) || $this->matchOneOfValue(TokenType::IDENTIFIER, self::IDENTIFIER_MATH_OPERATORS);
    }
}
