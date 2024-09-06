<?php

namespace Karboosx\Procer\IC;

class TokenInfo
{
    public function __construct(
        public int $line,
        public int $linePosition,
        public int $width,
    )
    {
    }
}