<?php

namespace Procer\Exception;

use Procer\Parser\Token;

class ParserException extends ProcerException
{
    private ?Token $token;
    private ?string $codeLine = null;

    public function __construct(string $message, ?Token $token = null)
    {
        $this->token = $token;
        if ($token === null) {
            parent::__construct($message);
            return;
        }


        parent::__construct($message . ' at line ' . $token->getLine() . ' position ' . $token->getLinePosition());
    }

    public function getToken(): ?Token
    {
        return $this->token;
    }

    public function setCodeLine(string $codeLine): void
    {
        $this->codeLine = $codeLine;
        $this->message = $this->constructMessage($this->message);
    }

    private function constructMessage(string $message): string
    {
        if ($this->codeLine === null || $this->token === null) {
            return $message;
        }

        $times = $this->token->getLinePosition();
        if ($times > 0) {
            $blanks = str_repeat(' ', $times);
        } else {
            $blanks = '';
        }

        $str_repeat = str_repeat('^', strlen($this->token->getValue()));

        return $message . PHP_EOL . $this->codeLine . PHP_EOL . $blanks . $str_repeat;
    }
}