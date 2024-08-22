<?php

namespace Procer\Signal;

enum SignalType
{
    case BEFORE_EXECUTION;
    case AFTER_EXECUTION;
}