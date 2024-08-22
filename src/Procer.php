<?php

namespace Procer;

use Procer\Exception\ProcerException;
use Procer\IC\ICParser;
use Procer\Parser\Parser;
use Procer\Parser\Tokenizer;
use Procer\Runner\Process;
use Procer\Runner\Runner;

class Procer
{
    private Runner $runner;

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
        $parser = new Parser(new Tokenizer());
        $icParser = new ICParser();
        $root = $parser->parse($code);

        return $icParser->parse($root);
    }
}