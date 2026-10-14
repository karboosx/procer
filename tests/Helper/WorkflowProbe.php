<?php

namespace Karboosx\Procer\Tests\Helper;

use Karboosx\Procer\Context;
use Karboosx\Procer\Exception\OperationFailure;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\ObjectFunctionProviderInterface;
use Karboosx\Procer\Interrupt\Interrupt;
use Karboosx\Procer\Interrupt\InterruptType;

class WorkflowProbe implements FunctionProviderInterface, ObjectFunctionProviderInterface
{
    public array $trace = [];
    public array $arrivals = [];
    public function __construct(public ?InterruptType $interrupt = null) {}
    public function supports(object|string $object, ?string $name = null): bool
    {
        return in_array($name ?? $object, ['mark', 'fail', 'fatal', 'step', 'next', 'as'], true);
    }
    public function mark(Context $context, mixed ...$args): mixed
    {
        if (isset($args[0]) && is_object($args[0])) { array_shift($args); }
        [$label, $value] = $args;
        $count = $this->arrivals[$label] = ($this->arrivals[$label] ?? 0) + 1;
        if ($this->interrupt === InterruptType::BEFORE_EXECUTION && $count === 1) {
            return new Interrupt($this->interrupt, null, $label);
        }
        $this->trace[] = $label;
        return $this->interrupt === InterruptType::AFTER_EXECUTION && $count === 1
            ? new Interrupt($this->interrupt, $value, $label) : $value;
    }
    public function fail(Context $context, mixed ...$args): never
    {
        if (isset($args[0]) && is_object($args[0])) { array_shift($args); }
        $label = $args[0] ?? 'failed';
        $this->trace[] = $label;
        throw new OperationFailure($label, 17);
    }
    public function fatal(Context $context, string $kind): never
    {
        throw match ($kind) {
            'type' => new \TypeError('bug'),
            'runner' => new \Karboosx\Procer\Exception\RunnerException('bug'),
            default => new \RuntimeException('bug'),
        };
    }
    public function step(Context $context): int { return 1; }
    public function next(Context $context, ?object $obj = null): int { return 2; }
    public function as(Context $context): int { return 3; }
}
