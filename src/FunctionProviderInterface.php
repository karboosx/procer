<?php

namespace Karboosx\Procer;

interface FunctionProviderInterface
{
    public function supports(string $functionName): bool;
}