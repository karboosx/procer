<?php

namespace Procer\IC;

class TokenInfo
{
    public function __construct(
        public int $line,
        public int $linePosition)
    {
    }
}