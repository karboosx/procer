<?php

namespace Karboosx\Procer\IC;

class IcPrinter
{
    public function prettify(IC $ic): string
    {
        $line = 0;
        $instructions = $ic->getInstructions();
        $output = '';

        foreach ($instructions as $instruction) {
            $output .= str_pad($line++, 4, ' ', STR_PAD_LEFT) . ' ' . $this->printInstruction($instruction);
        }

        return $output;
    }

    private function printInstruction(ICInstruction $instruction): string
    {
        $output = $instruction->getType()->name . ' ';
        $args = $instruction->getArgs();

        foreach ($args as $arg) {
            $output .= $arg . ' ';
        }

        return $output . PHP_EOL;
    }
}