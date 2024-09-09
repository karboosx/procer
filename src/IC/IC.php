<?php

namespace Karboosx\Procer\IC;

readonly class IC
{
    public function __construct(
        /**
         * @var ICInstruction[]
         */
        private array $instructions = [],

        /**
         * @var array<string, int>
         */
        private array $procedurePointers = [],
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

    /**
     * @return array<string, int>
     */
    public function getProcedurePointers(): array
    {
        return $this->procedurePointers;
    }

    public function getInstruction(int $currentInstructionIndex): ICInstruction
    {
        return $this->instructions[$currentInstructionIndex];
    }

    public function getProcedurePointer(string $procedureName): int
    {
        return $this->procedurePointers[$procedureName];
    }
}