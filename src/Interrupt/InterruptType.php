<?php

namespace Karboosx\Procer\Interrupt;

enum InterruptType
{
    case BEFORE_EXECUTION;
    case AFTER_EXECUTION;
}