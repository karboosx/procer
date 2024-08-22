<?php

namespace Procer\Tests;

use Procer\FunctionProviderInterface;
use Procer\Procer;
use Procer\Runner\Context;
use Procer\Serializer\Deserializer;
use Procer\Signal\Signal;
use Procer\Signal\SignalType;
use PHPUnit\Framework\TestCase;

class SignalTest extends TestCase
{
    public function testStopBefore()
    {
        $procer = new Procer([
            new class implements FunctionProviderInterface {

                public function test(Context $context)
                {
                    if ($context->get('a') === 1) {
                        $context->set('x', 'returned signal.');
                        $context->set('a', 2);
                        return new Signal(SignalType::BEFORE_EXECUTION);
                    }

                    $context->set('x', $context->get('x').' returned.');
                    return $context->get('x');
                }
                public function supports(string $functionName): bool
                {
                    return 'test';
                }
            },
        ]);

        $context = $procer->run('let a be 1. let b be test().');
        self::assertFalse($context->isFinished());
        $context = $procer->resume((new Deserializer())->deserialize($context->serialize()));

        self::assertSame("returned signal. returned.", $context->get('x'));
    }
}
