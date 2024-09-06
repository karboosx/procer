<?php

namespace Karboosx\Procer;

interface ObjectFunctionProviderInterface
{
    public function supports(string $className, string $functionName): bool;
}