<?php

namespace Procer\Parser;

readonly class Token
{

    public function __construct(
        public TokenType $type,
        public string    $value,
        public null|int  $line = null,
        public null|int  $linePosition = null
    )
    {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }

    public function isOneOf(TokenType ...$types): bool
    {
        if (in_array($this->type, $types, true)) {
            return true;
        }
        return false;
    }

    public function isNot(TokenType $type): bool
    {
        return $this->type !== $type;
    }

    public function getType(): TokenType
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLine(): null|int
    {
        return $this->line;
    }

    public function getLinePosition(): null|int
    {
        return $this->linePosition;
    }

    public static function create(TokenType $type, string $value, ?int $line = null, ?int $linePosition = null): Token
    {
        return new Token($type, $value, $line, $linePosition);
    }
}