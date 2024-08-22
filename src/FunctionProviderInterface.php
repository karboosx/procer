<?php

namespace Procer;

interface FunctionProviderInterface
{
    public function supports(string $functionName): bool;
}