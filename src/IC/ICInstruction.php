<?php

namespace Procer\IC;

class ICInstruction
{
    public function __construct(
        private readonly InstructionType $type,
        private array                    $args = [],
        private readonly ?TokenInfo      $token = null
    )
    {
    }

    public function getType(): InstructionType
    {
        return $this->type;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getTokenInfo(): ?TokenInfo
    {
        return $this->token;
    }

    public function setArg(int $key, $pointer): void
    {
        $this->args[$key] = $pointer;
    }
}
