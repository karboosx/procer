# Expressions

In Procer code you can use expressions to perform operations on variables or other values.

## Supported operators

- `+` - Addition
- `-` - Subtraction
- `*` - Multiplication
- `/` - Division
- `=` - Equal
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

The precedence of operators is as follows:
1. Parentheses
2. Subtraction, Addition
3. Multiplication, Division
4. Is, Is not, Greater than, Less than, Greater than or equal, Less than or equal
5. Logical AND
6. Logical OR
7. Logical NOT
8. Function call and Object function call

## Evaluation expression

If you want to evaluate just an expression, you can use the `runExpression` method of the `Karboosx\Procer` class.

```php
use Karboosx\Procer;

$procer = new Procer();

$result = $procer->runExpression('1 + 2 * 3');

echo $result; // 7
```