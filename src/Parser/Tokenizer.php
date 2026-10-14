<?php

namespace Karboosx\Procer\Parser;

use Karboosx\Procer\Exception\ParserException;

class Tokenizer
{
    public const MAX_SOURCE_BYTES = 1048576;
    public const MAX_TOKENS = 100000;

    /** @return Token[] */
    public function tokenize(string $code): array
    {
        $length = strlen($code);
        if ($length > self::MAX_SOURCE_BYTES) {
            throw new ParserException('Source exceeds the maximum size of ' . self::MAX_SOURCE_BYTES . ' bytes');
        }

        $tokens = [];
        $i = 0;
        $line = 1;
        $lineStart = 0;
        $indent = 0;
        $atLineStart = true;
        $punctuation = [
            '=' => TokenType::EQUALS, '{' => TokenType::LEFT_BRACE, '}' => TokenType::RIGHT_BRACE,
            '>' => TokenType::MORE_THEN, '<' => TokenType::LESS_THEN,
            '(' => TokenType::LEFT_PARENTHESIS, ')' => TokenType::RIGHT_PARENTHESIS,
            ',' => TokenType::COMMA, '.' => TokenType::DOT,
            '+' => TokenType::PLUS, '-' => TokenType::MINUS, '*' => TokenType::MULTIPLY,
            '/' => TokenType::DIVIDE, '%' => TokenType::MODULO,
        ];
        $pairs = ['!=' => TokenType::NOT_EQUALS, '>=' => TokenType::MORE_OR_EQUALS, '<=' => TokenType::LESS_OR_EQUALS];

        while ($i < $length) {
            $char = $code[$i];
            if ($char === "\n" || $char === "\r") {
                $i += $char === "\r" && ($code[$i + 1] ?? '') === "\n" ? 2 : 1;
                $line++;
                $lineStart = $i;
                $indent = 0;
                $atLineStart = true;
                continue;
            }
            if ($char === ' ' || $char === "\t") {
                $i++;
                if ($atLineStart) {
                    $indent++;
                }
                continue;
            }
            if ($char === '/' && ($code[$i + 1] ?? '') === '/') {
                $i += 2;
                while ($i < $length && $code[$i] !== "\r" && $code[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            $atLineStart = false;
            $start = $i;
            $tokenLine = $line;
            $position = $i - $lineStart;
            $tokenIndent = $indent;
            if (self::isLetter($char)) {
                do {
                    $i++;
                } while ($i < $length && (self::isLetter($code[$i]) || self::isDigit($code[$i]) || $code[$i] === '_'));
                $type = TokenType::IDENTIFIER;
            } elseif (self::isDigit($char)) {
                do {
                    $i++;
                } while ($i < $length && self::isDigit($code[$i]));
                $type = TokenType::NUMBER;
                // A statement's final period belongs to the next token.
                if (($code[$i] ?? '') === '.' && self::isDigit($code[$i + 1] ?? '')) {
                    $type = TokenType::NUMBER_DECIMAL;
                    $i++;
                    while ($i < $length && self::isDigit($code[$i])) {
                        $i++;
                    }
                }
            } elseif ($char === '"') {
                $type = TokenType::STRING;
                $i++;
                while ($i < $length && $code[$i] !== '"') {
                    if ($code[$i] === '\\' && in_array($code[$i + 1] ?? '', ['"', '\\'], true)) {
                        $i += 2;
                    } elseif ($code[$i] === "\r" || $code[$i] === "\n") {
                        $i += $code[$i] === "\r" && ($code[$i + 1] ?? '') === "\n" ? 2 : 1;
                        $line++;
                        $lineStart = $i;
                        $indent = 0;
                    } else {
                        $i++;
                    }
                }
                if ($i === $length) {
                    throw new ParserException('Unterminated string', new Token($type, substr($code, $start), $tokenIndent, $tokenLine, $position));
                }
                $i++;
            } elseif (isset($pairs[substr($code, $i, 2)])) {
                $type = $pairs[substr($code, $i, 2)];
                $i += 2;
            } else {
                $type = $punctuation[$char] ?? TokenType::UNKNOWN;
                $i++;
            }
            $token = new Token($type, substr($code, $start, $i - $start), $tokenIndent, $tokenLine, $position);
            if (count($tokens) >= self::MAX_TOKENS) {
                throw new ParserException('Source exceeds the maximum token count of ' . self::MAX_TOKENS, $token);
            }
            $tokens[] = $token;
        }
        return $tokens;
    }

    private static function isLetter(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z');
    }

    private static function isDigit(string $char): bool
    {
        return $char !== '' && $char >= '0' && $char <= '9';
    }
}
