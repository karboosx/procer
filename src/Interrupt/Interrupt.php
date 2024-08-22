<?php

namespace Procer\Interrupt;

final readonly class Interrupt
{
    public function __construct(
        private InterruptType $signalType = InterruptType::AFTER_EXECUTION,
        private mixed         $data = null
    )
    {
    }

    public function getSignalType(): InterruptType
    {
        return $this->signalType;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}