<?php

namespace Karboosx\Procer;

use Karboosx\Procer\Exception\IcParserException;
use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\Exception\ProcerException;
use Karboosx\Procer\IC\ICParser;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Runner\Process;
use Karboosx\Procer\Runner\Runner;

class Procer
{
    private Runner $runner;
    private bool $useDoneKeyword = false;

    public function __construct(array $functionProviders = [])
    {
        $this->runner = new Runner();

        foreach ($functionProviders as $provider) {
            $this->runner->addFunctionProvider($provider);
        }
    }

    public function addFunctionProvider($provider): void
    {
        $this->runner->addFunctionProvider($provider);
    }

    /** @noinspection PhpUnused */
    public function useDoneKeyword(bool $useDoneKeyword = true): void
    {
        $this->useDoneKeyword = $useDoneKeyword;
    }

    /**
     * @throws ProcerException
     */
    public function run(string $code, array $variables = [], array $signals = []): Context
    {
        $instructions = $this->getParsedCode($code);

        $this->runner->reset();

        $this->runner->loadGlobalVariables($variables);
        $this->runner->loadInstructions($instructions);
        $this->runner->loadSignals($signals);

        return $this->runner->run();
    }

    /**
     * @throws ProcerException
     */
    public function runExpression(string $expression, array $variables = [], array $signals = []): mixed
    {
        $instructions = $this->getParsedExpression($expression);

        $this->runner->reset();

        $this->runner->loadGlobalVariables($variables);
        $this->runner->loadInstructions($instructions);
        $this->runner->loadSignals($signals);

        return $this->runner->runExpression();
    }

    /**
     * @throws ProcerException
     */
    public function resume(?Process $process = null, array $variables = [], array $signals = []): Context
    {
        if ($process !== null) {
            $this->runner->loadProcess($process);
        } else {
            $this->runner->reset();
        }

        $this->runner->loadGlobalVariables($variables);
        $this->runner->loadSignals($signals);

        return $this->runner->run();
    }

    /**
     * @param string $code
     * @return IC\IC
     * @throws Exception\IcParserException
     * @throws Exception\ParserException
     */
    public function getParsedCode(string $code): IC\IC
    {
        $parser = new Parser(new Tokenizer(), $this->useDoneKeyword);
        $icParser = new ICParser();
        $root = $parser->parse($code);

        return $icParser->parse($root);
    }

    /**
     * @param string $code
     * @return IC\IC
     * @throws Exception\IcParserException
     * @throws Exception\ParserException
     */
    public function getParsedExpression(string $code): IC\IC
    {
        $parser = new Parser(new Tokenizer(), $this->useDoneKeyword);
        $icParser = new ICParser();
        $mathExpression = $parser->parseExpression($code);

        return $icParser->parseExpression($mathExpression);
    }

    /** @noinspection PhpUnused */
    /**
     * @throws IcParserException
     * @throws ParserException
     */
    public function printIcCode(string $code): string
    {
        $instructions = $this->getParsedCode($code);

        $this->runner->loadInstructions($instructions);

        return $this->runner->debugIc();
    }

    /** @noinspection PhpUnused */
    /**
     * @throws IcParserException
     * @throws ParserException
     */
    public function printIcExpression(string $expression): string
    {
        $instructions = $this->getParsedExpression($expression);

        $this->runner->loadInstructions($instructions);

        return $this->runner->debugIc();
    }
}