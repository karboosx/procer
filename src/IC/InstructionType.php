<?php

namespace Karboosx\Procer\IC;

enum InstructionType: int
{
    case PUSH_VALUE = 1;
    case PUSH_VARIABLE = 2;
    case MATH_OPERATOR = 3;
    case SET_VARIABLE = 4;
    case FUNCTION_CALL = 5;
    case OBJECT_FUNCTION_CALL = 6;
    case IF_NOT_JMP = 7;
    case STOP = 8;
    case JMP = 9;
    case INTERNAL_FUNCTION_CALL = 10;
    case NOP = 11;
}