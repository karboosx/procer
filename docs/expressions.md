# Expressions

In Procer code you can use expressions to perform operations on variables or other values.

## Supported operators

- `+` - Addition. If **either** operand is a string the result is string concatenation instead: `"count: " + 42` → `"count: 42"`.
- `.` - String concatenation (always, regardless of types): `"Hello" . " World"` → `"Hello World"`. Prefer this over `+` when you explicitly want strings joined.
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
2. Multiplication `*`, Division `/`, Modulo `%`
3. Addition `+`, Subtraction `-`
4. Comparison: `=`, `is`, `!=`, `is not`, `>`, `<`, `>=`, `<=`
5. Logical AND `and`
6. Logical OR `or`

## Evaluation expression

If you want to evaluate just an expression, you can use the `runExpression` method of the `Karboosx\Procer` class.

```php
use Karboosx\Procer;

$procer = new Procer();

$result = $procer->runExpression('1 + 2 * 3');

echo $result; // 7
```