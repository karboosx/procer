<?php

namespace Karboosx\Procer\Tests;

use Karboosx\Procer\Context;
use Karboosx\Procer\Debug\MathExpressionReflection;
use Karboosx\Procer\Exception\ParserException;
use Karboosx\Procer\FunctionProviderInterface;
use Karboosx\Procer\Parser\Parser;
use Karboosx\Procer\Parser\Tokenizer;
use Karboosx\Procer\Procer;
use Karboosx\Procer\Serializer\Deserializer;
use PHPUnit\Framework\TestCase;

class RecordExpressionTest extends TestCase
{
    /** @dataProvider expressions */
    public function testRecordsInEveryValueContext(string $expression, object $expected, bool $done, string $context): void
    {
        $procer = new Procer([new RecordProbe()]);
        $procer->useDoneKeyword($done);
        $source = match ($context) {
            'let' => "let result be $expression. return result.",
            'return' => "return $expression.",
            'argument' => "return identity(($expression)).",
            'procedure' => "procedure make do\n    return $expression.\n" . ($done ? "done\n" : '') . 'return make().',
            'nested' => "let result be ($expression) as \"nested\". return nested of result.",
        };
        $result = $procer->run($source, ['x' => 3, 'obj' => (object)['value' => 7]]);
        self::assertEquals($expected, $result->getReturnValue(), $source);
        self::assertSame([], $result->getProcess()->scopes[0]->stack);
        $restored = (new Deserializer())->deserialize($result->serialize());
        self::assertEquals($expected, (new Procer())->resume($restored)->getReturnValue());
    }

    public static function expressions(): iterable
    {
        $cases = [
            ['x as "test"', (object)['test' => 3]],
            ['value of obj as "value", x as "x"', (object)['value' => 7, 'x' => 3]],
            ['1 + 2 * 3 as "math", false and (1 / 0) as "lazy"', (object)['math' => 7, 'lazy' => false]],
            ['(x as "inner") as "outer", null as "nil"', (object)['outer' => (object)['inner' => 3], 'nil' => null]],
            ['true as "", x as "0", "unicode" as "żółć", 4 as "has space"', (object)['' => true, '0' => 3, 'żółć' => 'unicode', 'has space' => 4]],
            ['"a" as "A", "b" as "a", identity(9) as "call"', (object)['A' => 'a', 'a' => 'b', 'call' => 9]],
        ];
        foreach ($cases as [$expression, $expected]) {
            foreach ([false, true] as $done) {
                foreach (['let', 'return', 'argument', 'procedure', 'nested'] as $context) {
                    yield "$expression / $context / $done" => [$expression, $expected, $done, $context];
                }
            }
        }
    }

    /** @dataProvider malformedRecords */
    public function testRejectsMalformedRecords(string $source, bool $done): void
    {
        $this->expectException(ParserException::class);
        $procer = new Procer();
        $procer->useDoneKeyword($done);
        $procer->run($source);
    }

    public static function malformedRecords(): iterable
    {
        foreach ([
            'let a be 1 as.', 'let a be 1 as key.', 'let a be 1 as 2.', 'let a be 1 as "a",.',
            'let a be 1 as "a", 2.', 'let a be 1 as "a" 2 as "b".', 'let a be 1 as "a",, 2 as "b".',
            'let a be 1 as "same", 2 as "same".', 'let a be 1 as "0", 2 as "0".',
            'let a be (1 as "a", 2 as "b".', 'let a be 1 as "a") .',
            'let a be 1 as "a" as "b".', 'save(1 as "a", 2 as "b").',
            'let a be 1 as "a", 2 as "b",.', 'let a be 1 as "a", return 3.',
            "let a be 1 as \"\0bad\".", 'let result be calculate with left be 1, right be 2.',
        ] as $source) {
            foreach ([false, true] as $done) { yield [$source, $done]; }
        }
    }

    public function testFieldValuesAreEvaluatedInWrittenOrderOnceAndAssignmentIsAtomic(): void
    {
        $provider = new RecordProbe();
        $provider->fail = true;
        $procer = new Procer([$provider]);
        try {
            $procer->run('let result be "old". let result be mark(1) as "a", fragile(2) as "b", mark(3) as "c".');
            self::fail('Expected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('retry', $e->getMessage());
        }
        $context = $procer->resume(null, [], []);
        self::assertEquals((object)['a' => 1, 'b' => 2, 'c' => 3], $context->get('result'));
        self::assertSame([1, 2, 2, 3], $provider->trace);
        self::assertSame(['old', 'old'], $provider->destinations);
    }

    public function testSourceObjectsAndExistingDestinationCanSupplyFields(): void
    {
        $source = (object)['value' => 8];
        $result = (new Procer())->run('let x be 4. let x be x as "old", value of obj as "copied", obj as "reference".', ['obj' => $source]);
        self::assertSame(4, $result->get('x')->old);
        self::assertSame(8, $result->get('x')->copied);
        self::assertSame($source, $result->get('x')->reference);
        self::assertEquals((object)['value' => 8], $source);
    }

    public function testReflectionVisitsValuesButNotLabels(): void
    {
        $expression = Parser::default()->parseExpression('x as "fakeVariable", (read(value of obj) as "fakeFunction") as "nested"');
        $reflection = new MathExpressionReflection($expression);
        self::assertSame(['x', 'obj'], $reflection->getVariables());
        self::assertSame(['read'], $reflection->getFunctions());
        self::assertEquals((object)['answer' => 7], (new Procer())->runExpression('3 + 4 as "answer"'));
    }

    public function testMultilineRecordsAndExactKeyBytes(): void
    {
        foreach (["\n", "\r\n", "\r"] as $eol) {
            foreach (['', '  ', "\t"] as $root) {
                foreach ([false, true] as $done) {
                    $source = $root . 'let a be' . $eol . $root . '    1 as "one", // field' . $eol .
                        $root . '    (2 as "inner") as "two".' . $eol . $root . 'return a.';
                    $procer = new Procer(); $procer->useDoneKeyword($done);
                    self::assertEquals((object)['one' => 1, 'two' => (object)['inner' => 2]], $procer->run($source)->getReturnValue());
                }
            }
        }
        for ($byte = 1; $byte <= 127; $byte++) {
            $key = 'a' . chr($byte) . 'b';
            $literal = str_replace(['\\', '"'], ['\\\\', '\\"'], $key);
            $result = (new Procer())->run('return 42 as "' . $literal . '".');
            self::assertSame(42, $result->getReturnValue()->{$key});
            self::assertSame(42, (new Procer())->resume((new Deserializer())->deserialize($result->serialize()))->getReturnValue()->{$key});
        }
    }

    public function testRecordLabelsDoNotConsumeRangeBindingsOrFunctionCommas(): void
    {
        $provider = new RecordProbe();
        $context = (new Procer([$provider]))->run('let total be 0. from 1 to 3 as i do
    let total be total + i.
let result be combine((total as "total"), (i as "after")). return result.');
        self::assertEquals((object)['left' => (object)['total' => 6], 'right' => (object)['after' => 4]], $context->getReturnValue());
    }
}

class RecordProbe implements FunctionProviderInterface
{
    public array $trace = [];
    public array $destinations = [];
    public bool $fail = false;
    public function supports(string $name): bool { return in_array($name, ['identity', 'mark', 'fragile', 'combine'], true); }
    public function identity(Context $context, mixed $value): mixed { return $value; }
    public function mark(Context $context, mixed $value): mixed { $this->trace[] = $value; return $value; }
    public function fragile(Context $context, mixed $value): mixed
    {
        $this->trace[] = $value;
        $this->destinations[] = $context->get('result');
        if ($this->fail) { $this->fail = false; throw new \RuntimeException('retry'); }
        return $value;
    }
    public function combine(Context $context, mixed $left, mixed $right): object { return (object)compact('left', 'right'); }
}
