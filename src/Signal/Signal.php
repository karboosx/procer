<?php

namespace Procer\Signal;

final readonly class Signal
{
    public function __construct(
        private SignalType $signalType = SignalType::AFTER_EXECUTION,
        private mixed      $data = null
    )
    {
    }

    public function getSignalType(): SignalType
    {
        return $this->signalType;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}