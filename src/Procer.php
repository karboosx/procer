<?php

namespace Karboosx\Procer;

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

        $this->runner->loadGlobalVariables($variables);
        $this->runner->loadCode($instructions);

        return $this->runner->run();
    }

    /**
     * @throws ProcerException
     */
    public function resume(?Process $process = null, array $variables = [], array $signals = []): Context
    {
        if ($process !== null) {
            $this->runner->loadProcess($process);
        }

        $this->runner->loadGlobalVariables($variables);

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
}