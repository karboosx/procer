<?php

namespace Procer\IC;

readonly class IC
{
    public function __construct(
        /**
         * @var ICInstruction[]
         */
        private array $instructions = [],
    )
    {
    }

    /**
     * @return ICInstruction[]
     */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    public function getInstruction(int $currentInstructionIndex): ICInstruction
    {
        return $this->instructions[$currentInstructionIndex];
    }
}