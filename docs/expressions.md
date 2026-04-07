# Expressions

In Procer code you can use expressions to perform operations on variables or other values.

## Supported operators

- `+` - Addition (also string concatenation when either operand is a string)
- `-` - Subtraction
- `*` - Multiplication
- `/` - Division
- `%` - Modulo (remainder)
- `=` - Equal (loose comparison, same as `is`)
- `is` - Alias for `=` operator
- `!=` - Not equal
- `is not` - Alias for `!=` operator
- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal
- `and` - Logical AND
- `or` - Logical OR
- `not` - Logical NOT
- `func()` - Function call
- `on obj do func()` - Object function call
- `()` - Parentheses

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