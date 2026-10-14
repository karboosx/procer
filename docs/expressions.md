# Expressions

[Documentation index](README.md) · [Syntax](syntax.md) · [Records](records.md)

Expressions calculate values for assignments, conditions, function arguments, and returns. `let total be price * quantity.` is a statement; `price * quantity` is its expression.

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
- `value as "field"` - Record construction; commas add fields. See [records](records.md).
- `field of object` - Property/getter access, including chains such as `name of customer of order`.

## Precedence

Higher entries bind tighter. Parentheses override this grouping:

1. Parentheses `()`
   Prefix `not` and unary `-` apply to the next term (or parenthesized expression).
2. Multiplication `*`, Division `/`, Modulo `%`
3. Addition `+`, Subtraction `-`
4. Comparison: `=`, `is`, `!=`, `is not`, `>`, `<`, `>=`, `<=`
5. Logical AND `and`
6. Logical OR `or`
7. Record field labeling `as "field"` and record field separators

Binary operators at the same precedence level group from left to right. For example, `10 - 3 - 2` means `(10 - 3) - 2`; `1 + 2 * 3 as "total"` creates a record whose `total` is `7`. Group a record inside a function argument: `save((total as "amount", currency as "currency"))`. The inner parentheses delimit the record; the outer pair belongs to the call.

`and` and `or` short-circuit and return booleans. For example, `x exists and x > 0` does not read an undefined `x`. Function calls on the skipped side are not executed.

Repeated unary minus is supported: `--5` is `5`, and `5--3` is `8`. Division by zero raises `RunnerException`. Modulo converts both operands to integers by truncating toward zero; a divisor such as `0.5` therefore raises `RunnerException` too.

## Evaluation order

Binary operands evaluate left to right, except that `and` and `or` skip an unnecessary right operand. Record fields evaluate in their written order. Existing function, object-function, and procedure arguments evaluate **right to left**, then arrive in their declared positions: in `combine(first(), second())`, `second()` runs first but its result is the second argument. Record construction does not change that call behavior.

For a business process where call order matters, use separate `let` statements before the call. A failed record field prevents later fields from running, and the destination is assigned only when construction succeeds. Earlier callback side effects are retained. See [steps and recovery](workflows.md) for handled failures and [interrupts](interrupts.md) for resumable calls.

## Literals and values

Strings use double quotes. `\"` represents a quote and `\\` represents a backslash; other backslash sequences remain literal, so `\n` is two characters. Actual newlines and UTF-8 text are allowed inside strings. Identifiers use ASCII letters followed by letters, digits, or underscores.

Numbers use decimal integer or fractional notation, such as `12`, `3.5`, and `-2`. Integer literals must fit PHP's integer range; decimal literals and arithmetic results must be finite. Exponent notation is not supported. `true`, `false`, and `null` are literal values. There is no array literal syntax; pass arrays from PHP or provide a collection function. [Records](records.md) create `stdClass` objects.

Numeric operations accept numbers, booleans, `null`, and numeric strings where PHP's numeric conversion permits them. For `+`, a string operand selects concatenation instead of numeric addition. Concatenation accepts scalar values and stringable objects, but not arrays or non-stringable objects. Unsupported arithmetic inputs raise `RunnerException`. Comparisons use PHP's loose value semantics; there is no strict equality operator. Recursive or excessively deep comparisons are rejected rather than recursing without a bound.

`x exists` asks whether a variable is bound, even if its value is `null`. It does not test an object's field or call a function. To guard an optional input, write `x exists and x is not null`.

## Evaluating an expression from PHP

Use `runExpression()` on `Karboosx\Procer\Procer`. Supply the expression without a terminating period. The optional second argument is an array of globals, with variable names as keys.

<!-- verify:php {"output":"7"} -->
```php
<?php
require 'vendor/autoload.php';

use Karboosx\Procer\Procer;

$procer = new Procer();

$result = $procer->runExpression('1 + 2 * 3');

echo $result; // 7
```

If a function interrupts evaluation, `runExpression()` returns `null` and keeps the unfinished calculation intact for `resume()`. For expressions that may pause, prefer `run('return ... .')`: it gives you a `Context` for checking `isFinished()`, serializing, and reading the final value with `getReturnValue()` after resuming.
