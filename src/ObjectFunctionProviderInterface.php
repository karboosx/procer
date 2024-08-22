<?php

namespace Procer;

interface ObjectFunctionProviderInterface
{
    public function supports(string $className, string $functionName): bool;
}