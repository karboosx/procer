<?php

namespace Karboosx\Procer\Interrupt;

enum InterruptReason
{
    case FUNCTION_NOT_FOUND;
    case FUNCTION_REQUEST;
    case STOP;
    case RETURN;
    case WHILE_STOPPING;
    case WAIT_FOR_SIGNAL;
}