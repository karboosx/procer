<?php

namespace Procer\Parser;

final readonly class TokenValue
{
    public function __construct(
        public Token  $token,
        public string $value
    )
    {
    }
}