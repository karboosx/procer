# Expressions

In Procer code you can use expressions to perform operations on variables or other values.

## Supported operators

- `+` - Addition. If **either** operand is a string the result is string concatenation instead: `"count: " + 42` → `"count: 42"`.
- `.` terminates a statement; it is not an expression operator. Use `+` with a string operand for concatenation.
- `-` - Subtraction
- `*` - Multiplication
- `/` - Division
- `%` - Modulo (remainder)
- `=` / `is` - Equal (**loose** comparison — `0 is false` is `true`, `"1" is 1` is `true`). Use `is not` / `!=` for the inverse.
- `!=` / `is not` - Not equal (loose)
- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal
- `and` - Logical AND
- `or` - Logical OR
- `not` - Logical NOT (prefix)
- `exists` / `not exists` - Variable existence check (see [Syntax](syntax.md))
- `func()` - Function call
- `on obj do func()` - Object function call
- `()` - Parentheses for grouping

## Precedence

Higher entries bind tighter (evaluated first):

1. Parentheses `()`
   Prefix `not` and unary `-` apply to the next term (or parenthesized expression).
2. Multiplication `*`, Division `/`, Modulo `%`
3. Addition `+`, Subtraction `-`
4. Comparison: `=`, `is`, `!=`, `is not`, `>`, `<`, `>=`, `<=`
5. Logical AND `and`
6. Logical OR `or`

`and` and `or` short-circuit and return booleans. For example, `x exists and x > 0` does not read an undefined `x`. Function calls on the skipped side are not executed.

Repeated unary minus is supported: `--5` is `5`, and `5--3` is `8`. Integer literals must fit PHP's integer range. Decimal literals and arithmetic results must be finite. Modulo converts both operands to integers by truncating toward zero; a divisor such as `0.5` therefore raises `RunnerException`.

Strings use double quotes. `\"` represents a quote and `\\` represents a backslash; other backslash sequences remain literal, so `\n` is two characters. Actual newlines and UTF-8 text are allowed inside strings. Identifiers use ASCII letters followed by letters, digits, or underscores.

## Evaluation expression

If you want to evaluate just an expression, you can use the `runExpression` method of the `Karboosx\Procer` class.

```php
use Karboosx\Procer;

$procer = new Procer();

$result = $procer->runExpression('1 + 2 * 3');

echo $result; // 7
```

If a function interrupts evaluation, `runExpression()` returns `null` and keeps the unfinished calculation intact for `resume()`. For expressions that may pause, prefer `run('return ... .')`: it gives you a `Context` for checking `isFinished()`, serializing, and reading the final value with `getReturnValue()` after resuming.
