<?php

namespace Procer\IC;

class Label
{
    public function __construct(
        public ?int $pointer = null,
    )
    {
    }
}