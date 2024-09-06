<?php

namespace Karboosx\Procer\Parser;

enum TokenType: string
{
    case IDENTIFIER = 'IDENTIFIER';
    case NUMBER = 'NUMBER';
    case NUMBER_DECIMAL = 'NUMBER_DECIMAL';
    case STRING = 'STRING';
    case PLUS = 'PLUS';
    case MINUS = 'MINUS';
    case MULTIPLY = 'MULTIPLY';
    case DIVIDE = 'DIVIDE';
    case MODULO = 'MODULO';
    case EQUALS = 'EQUALS';
    case LEFT_BRACE = 'LEFT_BRACE';
    case RIGHT_BRACE = 'RIGHT_BRACE';
    case UNKNOWN = 'UNKNOWN';
    case NOT_EQUALS = 'NOT_EQUALS';
    case MORE_THEN = 'MORE_THEN';
    case LESS_THEN = 'LESS_THEN';
    case MORE_OR_EQUALS = 'MORE_OR_EQUALS';
    case LESS_OR_EQUALS = 'LESS_OR_EQUALS';
    case LEFT_PARENTHESIS = 'LEFT_PARENTHESIS';
    case RIGHT_PARENTHESIS = 'RIGHT_PARENTHESIS';
    case COMMA = 'COMMA';
    case DOT = 'DOT';
}