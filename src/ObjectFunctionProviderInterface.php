<?php

namespace Karboosx\Procer;

interface ObjectFunctionProviderInterface
{
    public function supports(object $object, string $functionName): bool;
}