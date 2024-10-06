<?php

namespace Karboosx\Procer\Interrupt;

enum InterruptType: int
{
    case BEFORE_EXECUTION = 0;
    case AFTER_EXECUTION = 1;
}