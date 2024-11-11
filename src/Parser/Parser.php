<?php

namespace Karboosx\Procer\Parser;

use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Parser\Node\{AbstractNode,
    BuildInValue,
    Not,
    OfAccess,
    ForEachLoop,
    FromLoop,
    FunctionCall,
    IfNode,
    Let,
    MathExpression,
    MathOperator,
    Nothing,
    Number,
    NumberDecimal,
    ObjectFunctionCall,
    Procedure,
    Reference,
    ReturnNode,
    Root,
    Stop,
    StringNode,
    WaitForSignal,
    WhileLoop};

class Parser
{
    private const PRECEDENCE = [
        TokenType::MULTIPLY->name => 2,
        TokenType::DIVIDE->name => 2,
        TokenType::MODULO->name => 2,
        TokenType::PLUS->name => 1,
        TokenType::MINUS->name => 1,
        TokenType::EQUALS->name => 0,
        TokenType::NOT_EQUALS->name => 0,
        TokenType::LESS_THEN->name => 0,
        TokenType::LESS_OR_EQUALS->name => 0,
        TokenType::MORE_THEN->name => 0,
        TokenType::MORE_OR_EQUALS->name => 0,
        IfNode::IS_OPERATOR => 0,
        IfNode::IS_NOT_OPERATOR => 0,
        IfNode::AND_KEYWORD => -1,
        IfNode::OR_KEYWORD => -2,

    ];

    private const BUILD_IN_VALUES = [
        'true',
        'false',
        'null',
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
        IfNode::IS_NOT_OPERATOR,
        IfNode::OR_KEYWORD,
        IfNode::AND_KEYWORD,
    ];

    const DONE_KEYWORD = 'done';

    /**
     * @var Token[]
     */
    private array $tokens;
    private int $currentTokenIndex;
    private int $amountOfTokens;


    public function __construct(
        private readonly Tokenizer $tokenizer,
        private readonly bool      $useDoneKeyword = false
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
                $rootStatements[] = $this->parseRootStatement();
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
    public function parseExpression(string $expression): MathExpression
    {
        try {
            $this->tokens = $this->tokenizer->tokenize($expression);

            $this->currentTokenIndex = 0;
            $this->amountOfTokens = count($this->tokens);

            return $this->parseMathExpression();
        } catch (ParserException $e) {
            $lines = explode("\n", $expression);
            if ($e->getToken() !== null) {
                $e->setCodeLine($lines[$e->getToken()->getLine() - 1]);
            }

            throw $e;
        }
    }


    /**
     * @throws ParserException
     */
    private function parseRootStatement(): AbstractNode
    {
        if ($this->matchValue(TokenType::IDENTIFIER, Procedure::PROCEDURE_KEYWORD)) {
            // procedure
            // procedure <IDENTIFIER> ([<IDENTIFIER>, ...]) do [<statement>, ...] done
            $procedureToken = $this->expectValue(TokenType::IDENTIFIER, Procedure::PROCEDURE_KEYWORD);
            return $this->parseProcedure($procedureToken);
        }

        return $this->parseStatement();
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
            // <IDENTIFIER>([<TERM>, ...]) on <IDENTIFIER>
            $functionNameToken = $this->expect(TokenType::IDENTIFIER);
            $node = $this->parseComplexFunctionCall($functionNameToken);
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

        if ($this->matchValue(TokenType::IDENTIFIER, FromLoop::FROM_KEYWORD)) {
            // from loop
            // from <EXPRESSION> to <EXPRESSION> [by <EXPRESSION>] [as <IDENTIFIER>] do [<statement>, ...] done
            $fromToken = $this->expectValue(TokenType::IDENTIFIER, FromLoop::FROM_KEYWORD);
            return $this->parseFromLoop($fromToken);
        }

        if ($this->matchValue(TokenType::IDENTIFIER, ForEachLoop::FOR_KEYWORD)) {
            // for each loop
            // for each <IDENTIFIER> in <IDENTIFIER> do [<statement>, ...] done
            $forToken = $this->expectValue(TokenType::IDENTIFIER, ForEachLoop::FOR_KEYWORD);
            return $this->parseForEachLoop($forToken);
        }

        if ($this->matchValue(TokenType::IDENTIFIER, WhileLoop::WHILE_KEYWORD) || $this->matchValue(TokenType::IDENTIFIER, WhileLoop::UNTIL_KEYWORD)) {
            // while loop
            // while <EXPRESSION> do [<statement>, ...] done
            $whileToken = $this->expect(TokenType::IDENTIFIER);
            return $this->parseWhileLoop($whileToken);
        }

        if ($this->matchValue(TokenType::IDENTIFIER, Nothing::NOTHING_KEYWORD)) {
            // nothing.
            $nothingToken = $this->expect(TokenType::IDENTIFIER);
            return $this->parseNothing($nothingToken);
        }

        if ($this->matchValue(TokenType::IDENTIFIER, ReturnNode::RETURN_KEYWORD)) {
            // return [<EXPRESSION>].
            $returnToken = $this->expect(TokenType::IDENTIFIER);
            return $this->parseReturn($returnToken);
        }

        if ($this->matchSpecialFunctionCall()) {
            // special function call.
            // <IDENTIFIER>.
            // <IDENTIFIER> on <IDENTIFIER>.

            $specialFunctionCallToken = $this->expect(TokenType::IDENTIFIER);
            $node = $this->parseSpecialFunctionCall($specialFunctionCallToken);
            $this->expect(TokenType::DOT);
            return $node;
        }

        if ($this->matchValue(TokenType::IDENTIFIER, WaitForSignal::WAIT_KEYWORD)) {
            // wait for signal.
            // wait for [signal] <IDENTIFIER>.
            $waitToken = $this->expect(TokenType::IDENTIFIER);
            $node = $this->parseWaitForSignal($waitToken);
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
    private function parseComplexFunctionCall(Token $functionNameToken): FunctionCall|ObjectFunctionCall
    {
        $functionCallNode = $this->parseFunctionCall($functionNameToken);

        if ($this->matchValue(TokenType::IDENTIFIER, ObjectFunctionCall::ON_KEYWORD)) {
            return $this->convertFunctionCallToObjectFunctionCall($functionCallNode);
        }

        return $functionCallNode;
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
    private function convertFunctionCallToObjectFunctionCall(FunctionCall $functionCall): ObjectFunctionCall
    {
        $node = new ObjectFunctionCall;
        $node->token = $functionCall->token;
        $node->functionName = $functionCall->functionName;
        $node->arguments = $functionCall->arguments;

        $this->expectValue(TokenType::IDENTIFIER, ObjectFunctionCall::ON_KEYWORD);

        $objectNameToken = $this->expect(TokenType::IDENTIFIER);
        $node->objectName = new TokenValue($objectNameToken, $objectNameToken->value);

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

        $indent = $this->getCurrentIndent();

        while (
            !$this->matchDoneKeyword($indent)
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
        } else if ($this->useDoneKeyword) {
            $this->expectValue(TokenType::IDENTIFIER, self::DONE_KEYWORD);
        }

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseFromLoop(Token $fromToken): FromLoop
    {
        $node = new FromLoop;
        $node->token = $fromToken;

        $node->from = $this->parseMathExpression();

        $this->expectValue(TokenType::IDENTIFIER, FromLoop::TO_KEYWORD);

        $node->to = $this->parseMathExpression();

        if ($this->matchValue(TokenType::IDENTIFIER, FromLoop::BY_KEYWORD)) {
            $this->consume();
            $node->step = $this->parseMathExpression();
        }

        if ($this->matchValue(TokenType::IDENTIFIER, FromLoop::AS_KEYWORD)) {
            $this->consume();
            $variableNameToken = $this->expect(TokenType::IDENTIFIER);
            $node->asVariable = new TokenValue($variableNameToken, $variableNameToken->value);
        }

        $this->expectValue(TokenType::IDENTIFIER, FromLoop::DO_KEYWORD);

        $indent = $this->getCurrentIndent();

        while (!$this->matchDoneKeyword($indent)) {
            $node->statements[] = $this->parseStatement();
        }

        if ($this->useDoneKeyword) {
            $this->expectValue(TokenType::IDENTIFIER, self::DONE_KEYWORD);
        }

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseForEachLoop(Token $forEachToken): ForEachLoop
    {
        $node = new ForEachLoop;
        $node->token = $forEachToken;

        $this->expectValue(TokenType::IDENTIFIER, ForEachLoop::EACH_KEYWORD);

        $variableNameToken = $this->expect(TokenType::IDENTIFIER);
        $node->asVariable = new TokenValue($variableNameToken, $variableNameToken->value);

        $this->expectValue(TokenType::IDENTIFIER, ForEachLoop::IN_KEYWORD);

        $node->arrayExpression = $this->parseMathExpression();

        $this->expectValue(TokenType::IDENTIFIER, ForEachLoop::DO_KEYWORD);

        $indent = $this->getCurrentIndent();

        while (!$this->matchDoneKeyword($indent)) {
            $node->statements[] = $this->parseStatement();
        }

        if ($this->useDoneKeyword) {
            $this->expectValue(TokenType::IDENTIFIER, self::DONE_KEYWORD);
        }

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseProcedure(Token $procedureToken): Procedure
    {
        $node = new Procedure();
        $node->token = $procedureToken;

        $procedureNameToken = $this->expect(TokenType::IDENTIFIER);
        $node->procedureName = new TokenValue($procedureNameToken, $procedureNameToken->value);

        $arguments = [];

        if ($this->match(TokenType::LEFT_PARENTHESIS)) {
            $this->consume();

            while (!$this->match(TokenType::RIGHT_PARENTHESIS)) {
                $argumentToken = $this->expect(TokenType::IDENTIFIER);
                $arguments[] = new TokenValue($argumentToken, $argumentToken->value);

                if ($this->match(TokenType::COMMA)) {
                    $this->consume();
                }
            }

            $this->expect(TokenType::RIGHT_PARENTHESIS);
        }

        $node->arguments = $arguments;

        $this->expectValue(TokenType::IDENTIFIER, Procedure::DO_KEYWORD);

        $indent = $this->getCurrentIndent();

        while (!$this->matchDoneKeyword($indent)) {
            $node->statements[] = $this->parseStatement();
        }

        if ($this->useDoneKeyword) {
            $this->expectValue(TokenType::IDENTIFIER, self::DONE_KEYWORD);
        }

        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseWhileLoop(Token $whileToken): WhileLoop
    {
        $node = new WhileLoop;
        $node->token = $whileToken;

        if ($this->matchValue(TokenType::IDENTIFIER, WhileLoop::STOPPING_KEYWORD)) {
            $this->consume();
            $node->stopping = true;
        }

        $expression = $this->parseMathExpression();
        $node->expression = $expression;

        $this->expectValue(TokenType::IDENTIFIER, WhileLoop::DO_KEYWORD);

        $indent = $this->getCurrentIndent();

        while (!$this->matchDoneKeyword($indent)) {
            $node->statements[] = $this->parseStatement();
        }

        if ($this->useDoneKeyword) {
            $this->expectValue(TokenType::IDENTIFIER, self::DONE_KEYWORD);
        }

        return $node;
    }


    /**
     * @throws ParserException
     */
    private function parseReference(): BuildInValue|Reference
    {
        $token = $this->consume();
        if (in_array($token->value, self::BUILD_IN_VALUES)) {
            $reference = new BuildInValue($token->value);
            $reference->token = $token;
            return $reference;
        }

        $reference = new Reference($token->value);
        $reference->token = $token;
        return $reference;
    }

    /**
     * @throws ParserException
     */
    private function parseNothing(Token $nothingToken): Nothing
    {
        $node = new Nothing;
        $node->token = $nothingToken;
        $this->expect(TokenType::DOT);
        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseReturn(Token $returnToken): ReturnNode
    {
        $node = new ReturnNode;
        $node->token = $returnToken;

        if ($this->matchValue(TokenType::IDENTIFIER, Nothing::NOTHING_KEYWORD)) {
            $this->consume();
        } else {
            $node->expression = $this->parseMathExpression();
        }

        $this->expect(TokenType::DOT);

        return $node;
    }

    private function matchSpecialFunctionCall(): bool
    {
        return ($this->match(TokenType::IDENTIFIER) && $this->match(TokenType::DOT, 1)) || $this->matchSpecialObjectFunctionCall();
    }

    public function matchSpecialObjectFunctionCall(): bool
    {
        return ($this->match(TokenType::IDENTIFIER) && $this->matchValue(TokenType::IDENTIFIER, ObjectFunctionCall::ON_KEYWORD, 1)
            && $this->match(TokenType::IDENTIFIER, 2) && $this->match(TokenType::DOT, 3));
    }

    private function parseSpecialFunctionCall(Token $specialFunctionCallToken): FunctionCall|ObjectFunctionCall
    {
        if ($this->matchValue(TokenType::IDENTIFIER, ObjectFunctionCall::ON_KEYWORD)) {
            // special object function call
            // <IDENTIFIER> on <IDENTIFIER> <IDENTIFIER>.
            $onToken = $this->expectValue(TokenType::IDENTIFIER, ObjectFunctionCall::ON_KEYWORD);

            $objectToken = $this->expect(TokenType::IDENTIFIER);
            $objectFunctionCall = new ObjectFunctionCall();
            $objectFunctionCall->objectName = new TokenValue($objectToken, $objectToken->value);
            $objectFunctionCall->functionName = new TokenValue($specialFunctionCallToken, $specialFunctionCallToken->value);
            $objectFunctionCall->token = $specialFunctionCallToken;

            return $objectFunctionCall;
        } else {
            // special function call
            // <IDENTIFIER>.
            $functionCall = new FunctionCall();
            $functionCall->functionName = new TokenValue($specialFunctionCallToken, $specialFunctionCallToken->value);
            $functionCall->token = $specialFunctionCallToken;

            return $functionCall;
        }
    }

    /**
     * @throws ParserException
     */
    private function parseWaitForSignal(Token $waitToken): WaitForSignal
    {
        $node = new WaitForSignal();
        $node->token = $waitToken;

        $this->expectValue(TokenType::IDENTIFIER, WaitForSignal::FOR_KEYWORD);

        if ($this->matchValue(TokenType::IDENTIFIER, WaitForSignal::ALL_KEYWORD)) {
            $node->all = true;
            $this->consume();
        }

        if ($this->matchValue(TokenType::IDENTIFIER, WaitForSignal::SIGNALS_KEYWORD)) {
            $this->consume();
        } else if ($this->matchValue(TokenType::IDENTIFIER, WaitForSignal::SIGNAL_KEYWORD)) {
            $this->consume();
        }

        $parseNext = true;

        while ($parseNext) {
            $signalNameToken = $this->expect(TokenType::IDENTIFIER);
            $node->signalNames[] = new TokenValue($signalNameToken, $signalNameToken->value);

            if ($this->match(TokenType::COMMA)) {
                $this->consume();
            } else {
                $parseNext = false;
            }
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
        if ($this->matchValue(TokenType::IDENTIFIER, IfNode::NOT_KEYWORD)) {
            return $this->parseNotSingleTermToken();
        }

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
            return $this->parseComplexFunctionCall($token);
        } else if ($this->match(TokenType::IDENTIFIER) && $this->peekValueLower() === ObjectFunctionCall::ON_KEYWORD) {
            $onToken = $this->expect(TokenType::IDENTIFIER);
            return $this->parseObjectFunctionCall($onToken);
        } else if ($this->match(TokenType::IDENTIFIER) && $this->matchValue(TokenType::IDENTIFIER, OfAccess::OF_KEYWORD, 1)) {
            return $this->parseDotAccess();
        }  else if ($this->match(TokenType::IDENTIFIER) && !$this->matchSpecialObjectFunctionCall()) {
            return $this->parseReference();
        } else if ($this->matchSpecialFunctionCall()) {
            $specialFunctionCallToken = $this->expect(TokenType::IDENTIFIER);
            return $this->parseSpecialFunctionCall($specialFunctionCallToken);
        } else if ($this->match(TokenType::LEFT_PARENTHESIS)) {
            $this->consume();
            $node = $this->parseMathExpression();
            $this->expect(TokenType::RIGHT_PARENTHESIS);
            return $node;
        }else {
            $this->throwUnexpectedToken('WRONG_TERM');
        }
    }

    /**
     * @throws ParserException
     */
    private function parseNotSingleTermToken(): Not
    {
        $notToken = $this->expectValue(TokenType::IDENTIFIER, IfNode::NOT_KEYWORD);
        $node = new Not($this->parseSingleTermToken());
        $node->token = $notToken;
        return $node;
    }

    /**
     * @throws ParserException
     */
    private function parseDotAccess(): OfAccess
    {
        $node = new OfAccess();
        $node->token = $this->peek();

        $node->pathParts = [];

        while ($this->match(TokenType::IDENTIFIER) && $this->matchValue(TokenType::IDENTIFIER, OfAccess::OF_KEYWORD, 1)) {
            $token = $this->consume();
            $node->pathParts[] = new TokenValue($token, $token->value);
            $this->consume();
        }

        $token = $this->expect(TokenType::IDENTIFIER);
        $node->pathParts[] = new TokenValue($token, $token->value);

        return $node;
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
            if ($operator->value === IfNode::IS_OPERATOR && $this->peekValue() === IfNode::NOT_KEYWORD) {
                $operator = $this->consume();
                $operator = new Token(TokenType::IDENTIFIER, IfNode::IS_NOT_OPERATOR, $operator->indent, $operator->line, $operator->linePosition);
            }
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

    private function getPrecedence(Token $operator): int
    {
        if (array_key_exists($operator->type->value, self::PRECEDENCE)) {
            return self::PRECEDENCE[$operator->type->value];
        }

        if (array_key_exists($operator->value, self::PRECEDENCE)) {
            return self::PRECEDENCE[$operator->value];
        }

        $this->throwUnexpectedToken('Not supported operator precedence');
    }

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

    /**
     * @return bool
     */
    public function matchConditionOperator(): bool
    {
        return $this->matchOneOf(self::TOKEN_MATH_OPERATORS) || $this->matchOneOfValue(TokenType::IDENTIFIER, self::IDENTIFIER_MATH_OPERATORS);
    }

    private function getCurrentIndent(): int
    {
        $token = $this->peek();

        if ($token === null) {
            return -1;
        }

        return $token->indent;
    }

    private function sameIndent(int $indent): bool
    {
        if ($this->getCurrentIndent() < 0) {
            return false;
        }

        return $this->getCurrentIndent() < $indent;
    }

    private function endOfFile(): bool
    {
        return $this->peek() === null;
    }

    /**
     * @param int $indent
     * @return bool
     */
    public function matchDoneKeyword(int $indent): bool
    {
        if ($this->useDoneKeyword) {
            return $this->matchValue(TokenType::IDENTIFIER, self::DONE_KEYWORD);
        }

        return $this->sameIndent($indent) || $this->endOfFile();
    }
}
