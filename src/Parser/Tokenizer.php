<?php

namespace Karboosx\Procer\Parser;

use Karboosx\Procer\Exception\ParserException;

class Tokenizer
{
    private int $line = 1;
    private int $processedCharacters = 0;
    private int $i = 0;
    private int $indent = 0;
    private bool $justIndent = false;

    /**
     * @throws ParserException
     */
    public function tokenize($code): array
    {
        $tokens = [];
        $length = strlen($code);
        $this->i = 0;
        $this->line = 1;
        $this->processedCharacters = 0;
        $this->indent = 0;
        $this->justIndent = false;

        $singleCharTokens = [
            '=' => TokenType::EQUALS,
            '{' => TokenType::LEFT_BRACE,
            '}' => TokenType::RIGHT_BRACE,
            '>' => TokenType::MORE_THEN,
            '<' => TokenType::LESS_THEN,
            '(' => TokenType::LEFT_PARENTHESIS,
            ')' => TokenType::RIGHT_PARENTHESIS,
            ',' => TokenType::COMMA,
            '.' => TokenType::DOT,
        ];

        $mathOperators = [
            '+' => TokenType::PLUS,
            '-' => TokenType::MINUS,
            '*' => TokenType::MULTIPLY,
            '/' => TokenType::DIVIDE,
            '%' => TokenType::MODULO,
        ];

        $doubleCharTokens = [
            '!' => ['=' => TokenType::NOT_EQUALS],
            '>' => ['=' => TokenType::MORE_OR_EQUALS],
            '<' => ['=' => TokenType::LESS_OR_EQUALS],
        ];

        while ($this->i < $length) {

            if ($code[$this->i] == "\n") {
                $this->line++;
                $this->i++;
                $this->processedCharacters = $this->i;
                $this->indent = 0;
                $this->justIndent = true;
                continue;
            }

            // Skip whitespace
            if (ctype_space($code[$this->i])) {
                $this->i++;
                if ($this->justIndent) {
                    $this->indent++;
                }
                continue;
            }

            // Skip comments
            if ($code[$this->i] == '/' && $this->i + 1 < $length && $code[$this->i + 1] == '/') {
                $this->i += 2;
                while ($this->i < $length && $code[$this->i] != "\n") {
                    $this->i++;
                }
                continue;
            }

            // Match identifiers (variables, function names, etc.)
            if (ctype_alpha($code[$this->i])) {
                $start = $this->i;
                while ($this->i < $length && (ctype_alnum($code[$this->i]) || $code[$this->i] == '_')) {
                    $this->i++;
                }
                $tokens[] = $this->createToken(
                    TokenType::IDENTIFIER,
                    substr($code, $start, $this->i - $start)
                );
                continue;
            }

            // Match numbers (including negative and decimal numbers)
            if ($code[$this->i] == '-' || ctype_digit($code[$this->i])) {
                $start = $this->i;
                if ($code[$this->i] == '-') {
                    $this->i++;
                }
                $isDecimal = false;
                $decimalPosition = 0;
                while ($this->i < $length && (ctype_digit($code[$this->i]) || $code[$this->i] == '.')) {
                    if ($code[$this->i] == '.') {
                        if ($isDecimal) {
                            break; // Second decimal point encountered, stop
                        }
                        $isDecimal = true;
                        $decimalPosition = $this->i;
                    }
                    $this->i++;
                }

                // if didnt take any number after minus, we have an invalid number, and we should move to the next checks
                if ($this->i == $start + 1 && $code[$start] == '-') {
                    $this->i = $start;
                } else {
                    if ($isDecimal && $decimalPosition < $this->i - 1) {
                        $tokens[] = $this->createToken(
                            TokenType::NUMBER_DECIMAL,
                            substr($code, $start, $this->i - $start)
                        );
                    } else {
                        if ($isDecimal) {
                            $tokens[] = $this->createToken(
                                TokenType::NUMBER,
                                substr($code, $start, $this->i - $start - 1)
                            );
                            $this->i--;
                        } else {
                            $tokens[] = $this->createToken(
                                TokenType::NUMBER,
                                substr($code, $start, $this->i - $start)
                            );
                        }
                    }
                    continue;
                }

            }

            // Match strings
            if ($code[$this->i] == '"') {
                $start = $this->i;
                $this->i++;
                while ($this->i < $length && $code[$this->i] != '"') {
                    $this->i++;
                }

                // if last character is not a quote, we have an unterminated string
                if ($this->i > $length) {
                    throw new ParserException('Unterminated string', $this->createToken(TokenType::STRING, substr($code, $start, $this->i - $start)));
                }

                $this->i++; // Skip closing quote

                $tokens[] = $this->createToken(
                    TokenType::STRING,
                    substr($code, $start, $this->i - $start)
                );
                continue;
            }

            // Match double character operators and punctuation
            if (isset($doubleCharTokens[$code[$this->i]])) {
                $start = $this->i;
                $this->i++;
                if ($this->i < $length && isset($doubleCharTokens[$code[$start]][$code[$this->i]])) {
                    $tokens[] = $this->createToken(
                        $doubleCharTokens[$code[$start]][$code[$this->i]],
                        $code[$start] . $code[$this->i], 1);
                    $this->i++;
                    continue;
                }
                $this->i = $start;
            }

            // Match single character operators and punctuation
            if (isset($singleCharTokens[$code[$this->i]])) {
                $tokens[] = $this->createToken(
                    $singleCharTokens[$code[$this->i]],
                    $code[$this->i], 1);
                $this->i++;
                continue;
            }

            // match math operators
            if (isset($mathOperators[$code[$this->i]])) {
                $tokens[] = $this->createToken(
                    $mathOperators[$code[$this->i]],
                    $code[$this->i], 1);
                $this->i++;
                continue;
            }

            // If we reach here, we have an unknown character
            $tokens[] = $this->createToken(TokenType::UNKNOWN, $code[$this->i], 1);
            $this->i++;
        }

        return $tokens;
    }

    private function createToken($type, string $value, int $add = 0): Token
    {
        $linePosition = $this->i - $this->processedCharacters - strlen($value) + $add;
        return Token::create($type, $value, $this->indent, $this->line, $linePosition);
    }
}