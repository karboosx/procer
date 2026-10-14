# Syntax

[Documentation index](README.md) · [Getting started](getting_started.md) · [Expressions](expressions.md)

This reference covers statements and block structure. Functions and objects such as `console` in the fragments below must be supplied by your application; their names are examples, not built-in services.

Here is a program that assigns a value and passes it to an object function:
```procer
let x be "Hello, World!".
on console print(x).
```

Ordinary statements end with a period. Block headers end with `do`, and an explicit `done` closes a block without a period. Commas separate arguments or record fields inside a statement; line breaks alone do not finish a statement.

Names start with an ASCII letter, followed by letters, digits, or underscores. Names are case-sensitive. Grammar keywords are lowercase; many are contextual, so avoid relying on keyword-shaped names for readability. Use `//` for comments through the end of a line. Strings use double quotes; see [literals and values](expressions.md#literals-and-values) for escaping and numeric limits.

## Table of contents

- [Indentation](#indentation)
- [Assigning values](#assigning-values)
- [Calling functions](#calling-functions)
  - [Calling functions on objects](#calling-functions-on-objects)
  - [Reverse order of function call](#reverse-order-of-function-call)
- [Conditional statements](#conditional-statements)
- [Checking whether a variable exists](#checking-whether-a-variable-exists)
- [Loops](#loops)
- [Stop execution](#stop-execution)
- [Nothing](#nothing)
- [Procedures](#procedures)
- [Returning values](#returning-values)
  - [Returning value from main script](#returning-value-from-main-script)
- [Accessing methods and properties of objects](#accessing-methods-and-properties-of-objects)
- [Working with multiple objects](#working-with-multiple-objects)
- [Records](#records)
- [Named steps and failures](#named-steps-and-failures)
- [Continuing a named loop](#continuing-a-named-loop)

## Indentation
The language relies on indentation to define blocks of code. Each block of code must have the same level of indentation. Here is an example:
```procer
if x is "Hello, World!" do
    on console print("x is Hello, World!").
```

In indentation mode, every block requires a body on a line after its `do`, indented further than the start of its header. This also applies when the header spans multiple lines. Use `nothing.` for an empty body. Sibling statements must have equal indentation; `or` and `if not do` align with their owning `if`. The first statement establishes the top-level indentation, so a uniformly indented embedded script is valid. LF, CRLF, and CR line endings are supported. Each space or tab counts as one indentation unit; use a consistent style.

To use explicit block closers, enable `useDoneKeyword()` on `Karboosx\Procer\Procer` before compiling or running a script:
```php
$procer = new \Karboosx\Procer\Procer();
$procer->useDoneKeyword();
```

With this flag set, you can write the code like this:
<!-- verify:procer done -->
```procer
if x is "Hello, World!" do on console print("x is Hello, World!"). done
```

or like this:
<!-- verify:procer done -->
```procer
if x is "Hello, World!" do
    on console print("x is Hello, World!").
done
```

In this mode, indentation is cosmetic and each block needs `done`. A conditional's branches share one closer; a step and its optional failure handler also share one closer. Do not mix the two block modes. Use `useDoneKeyword(false)` to restore indentation mode.

## Assigning values
Use `let name be expression.` to create or update a variable. Values can include strings, numbers, booleans, `null`, records, function results, and arrays or objects supplied by PHP:
```procer
let x be "Hello, World!".
let y be 42.
let z be true.
let w be add(1, 2).
```

## Calling functions

Function calls continue to use parentheses, such as `calculate(10, 20)`. Named calls using `with` are not part of the language.

To call a function, you can use the function name followed by the arguments in parentheses. If the function returns a value, you can assign it to a variable using the `let` keyword.
```procer
some_function().
function_with_arguments("argument A", "argument B").
let x be func().
let y be add(3, 4).
```

If function doesn't require any arguments, you can drop the parentheses. For example:
```procer
some_function.
```

### Calling functions on objects

If you want to call a function on an object, you can use the `on` keyword followed by the object name. Here are some examples:
```procer
on shopping_cart add("apple").
on file delete().
```

When calling a function on an object, you can prefix the function name with a verb to make the code more readable. For example:
```procer
on user_account do confirm().
on file run delete().
```

Also, when calling a function on an object, you can drop the parentheses if the function does not take any arguments. For example:
```procer
on user_account do logout.
on file run delete.
```

### Reverse order of function call

If you prefer to write the function call before the object, you can do that like this:
```procer
add("apple") on shopping_cart.
delete() on file.
```

In this case, you also can drop the parentheses if the function does not take any arguments. These calls are dispatched to registered [object function providers](object_functions.md), not arbitrary PHP methods. Keep `()` in expression contexts to distinguish a function call from a variable read.

```procer
confirm on user_account.
```

## Conditional statements
To create a conditional statement, you can use the `if` keyword followed by the condition you want to check. If the condition is true, the code inside the block will be executed. Here is an example:
```procer
if x is "Hello, World!" do
    on console print("x is Hello, World!").
```

You can also use the `if not` keyword to execute code when the previous condition is false. Here is an example:
```procer
if x is "Hello, World!" do
    on console print("x is Hello, World!").
if not do
    on console print("x is not Hello, World!").
```

If you want to check multiple conditions, you can use the `or` keyword. Here is an example:
```procer
if x is "Hello, World!" do
    on console print("x is Hello, World!").
or x is "Goodbye, World!" do
    on console print("x is Goodbye, World!").
```

## Checking whether a variable exists
To check if a variable exists, you can use the `if x exists` statement. This is useful to avoid errors when trying to access a variable that may not be defined. Here is an example:
```procer
if x exists do
    on console print("x is defined!").
```

You can also check if a variable does not exist using `if x not exists`:
```procer
if x not exists do
    on console print("x is not defined yet!").
```

## Loops

The loop forms are `from`, `for each`, `while`, and `until`. Here are some examples:
```procer
from 1 to 10 do
    on console print("doing a loop").
```

If you want to access the current index in the loop, you can use the `as` keyword. Here is an example:
```procer
from 1 to 10 as i do
    on console print(i).
```

To iterate over a list, you can use the `for each` loop. Here is an example:
```procer
for each item in list do
    on console print(item).
```

To create a loop that runs while a condition is true, you can use the `while` loop. Here is an example:
```procer
let i be 0.
while i < 10 do
    on console print(i).
    let i be i + 1.
```

`until` is the inverse of `while` — it loops **until** the condition becomes true:
```procer
let i be 0.
until i >= 10 do
    let i be i + 1.
// i is 10
```

`from` accepts an optional step size with `by`:
```procer
from 0 to 10 by 2 as i do
    on console print(i).  // prints 0, 2, 4, 6, 8, 10
```

A negative step supports descending ranges, for example `from 3 to 1 by -1 as i do`. Bounds and steps must be finite numbers. A zero step or a step too small to advance the current value raises `RunnerException`. A range whose initial value is already beyond its end in the step's direction has no iterations. `for each` visits array values in insertion order, including associative and sparse arrays.

Range endpoints are inclusive. The start and step are evaluated once; the end expression is evaluated at each condition check. `for each` requires a PHP array and takes its values at loop entry; changing the source array does not change that iteration list. It does not expose array keys. `while` and `until` test their condition before each iteration.

Loop variables remain in scope. A `from` index holds the first value beyond the endpoint after normal completion; a `for each` variable holds the last visited value. An empty `for each` does not assign its variable, so a previous value remains, or an absent variable remains absent. See [named loop continuation](#continuing-a-named-loop) for skipping an iteration and [stopping loops](in_depth.md#the-stopping-keyword) for yielding to PHP.

## Stop execution
To stop the execution of the program, you can use the `stop` keyword. Here is an example:
```procer
if x is "Hello, World!" do
    stop.
```

Execution pauses and PHP receives a context with `isFinished()` equal to `false`. Call `$procer->resume()` to continue in the same interpreter, or pass a restored `Process` to resume saved state. See [interrupts](interrupts.md) and [serialization](serialization.md).

> **Note:** Resumed program will start right after the `stop` statement.

## Nothing

If you want to do nothing in a block of code, you can use the `nothing` keyword. Here is an example:
```procer
if x is "Hello, World!" do
    nothing.
```

The longer form `nothing do nothing.` is also valid and does the same thing.

## Procedures

To define a procedure, you can use the `procedure` keyword followed by the procedure name, optional arguments and the code you want to execute. Here is an example:
```procer
procedure greet(name) do
    on console print("Hello, " + name + "!").
```

Arguments are optional and can be omitted. Here is an example:
```procer
procedure greet do
    on console print("Hello, World!").
```

You can create multiple arguments by separating them with a comma. Here is an example:
```procer
procedure greet(name, age) do
    on console print("Hello, " + name + "! You are " + age + " years old.").
```

> **Note:** You can only define procedures at the top level of the script.

Procedure names and parameter names within a procedure must be unique. Calls must supply exactly the declared number of arguments. Argument lists require commas between values and do not allow trailing commas.

> **Note:** Procedures are **hoisted** — you can call a procedure before its definition appears in the script.

Inside a procedure, reads look in that procedure's local scope first, then the global scope. They do not search the caller's local variables. A `let` updates an existing local variable or parameter first; otherwise it updates an existing global with that name, or creates a new local. Parameters can therefore shadow globals. New locals disappear when the procedure returns; ordinary `if`, loop, and step blocks do not introduce a new scope.

```procer
let counter be 0.

procedure increment do
    let counter be counter + 1.  // modifies the global 'counter'

procedure init do
    let temp be 42.              // local to init, gone after it returns

increment.
increment.
// counter is now 2, temp does not exist here
```

To call a procedure, you can use the procedure name followed by the arguments in parentheses. Here is an example:
```procer
procedure hello do
    return "Hello, World!".

let message be hello().
```
## Returning values

To return a value from a procedure, you can use the `return` keyword followed by the value you want to return. Here is an example:
```procer
procedure add(a, b) do
    return a + b.
```

If you don't want to return anything you can use a bare `return` or `return nothing`:
```procer
procedure do_nothing do
    return nothing.
```

`return nothing.` and `return.` both exit the procedure with `null`. Reaching the end of a procedure also returns `null`. The caller's `let x be do_nothing().` therefore sets `x` to `null`.

## Returning value from main script

To return a value from the main script, you can use the `return` keyword followed by the value you want to return. Here is an example:
```procer
return "Hello, World!".
```

Read the returned value with `getReturnValue()` on the `Context` returned by `$procer->run($script)`. Check `isFinished()` first when the script might pause. A top-level `return.` also completes the process with `null`.

Here is an example:
```php
$procer = new \Karboosx\Procer\Procer();

$result = $procer->run('return "Hello, World!".');

echo $result->getReturnValue(); // Output: Hello, World!
```

## Accessing methods and properties of objects

To access a method or property of an object, you can use the `of` keyword followed by the object name. Here are some examples:
```procer
let x be name of user.
```

This reads the `name` property or a supported getter on the `user` object.

Order of which the procer will check for the method or property is as follows:
1. Check if the object has a public property with the given name.
2. Check if the object has a public method with the given name.
3. Check if the object has a getter (`get<ucfirst_name>`) method with the given name.
4. Check if the object has a getter (`is<ucfirst_name>`) method with the given name.
5. Check if the object has a getter (`has<ucfirst_name>`) method with the given name.

Methods must be public and require no arguments. An uninitialized public typed property raises an error rather than falling back to a getter. Missing members and non-object receivers fail; there is no automatic array lookup or magic-method fallback. See [object access](object_functions.md#reading-with-of-is-a-different-operation) for the PHP contract.

### Working with multiple objects

If you want to access a method or property of an object that is a property of another object, you can use the `of` keyword followed by the object name. Here are some examples:
```procer
let x be name of user of account.
```

You can chain multiple objects together to access nested properties or methods.

## Records

Create a fresh record by giving each value a quoted field name:

```procer
let result be
    name of customer as "name",
    total as "amount".
```

The expressions before `as` supply values; the string literals after `as` name fields. Commas separate fields and a single period ends the statement. One field follows the same rule: `let result be total as "amount".` Read identifier-shaped fields with existing property access: `amount of result`.

Records are PHP `stdClass` values. Field names are case-sensitive string literals, including empty, numeric-looking, escaped, and Unicode names. Duplicate names and NUL bytes in names are rejected. There must be at least one field; trailing commas are not allowed. Non-identifier field names can be accessed by application-provided functions or by PHP. Construction copies the values into a new record; object-valued fields retain the usual object references, rather than deep-cloning their sources.

Each field accepts an ordinary expression. Fields evaluate from left to right; the expressions within them retain their existing precedence and [evaluation order](expressions.md#evaluation-order). The destination variable is assigned only after all fields succeed. Prior variable assignments and external callback side effects are not rolled back by a failure.

Use parentheses to delimit nested records:

```procer
let result be
    total as "amount",
    (name of customer as "name", email of customer as "email") as "customer".
```

Record expressions can appear directly after `let ... be`, after `return`, or in `runExpression()`. In function arguments or other surrounding expressions, parentheses make their commas unambiguous:

```procer
let result be total as "amount", currency as "currency".
save(result).
// Equivalent single expression:
save((total as "amount", currency as "currency")).
```

`as "field"` constructs records; the existing `from ... as index` still binds a loop index. Quoted labels distinguish these forms. Line breaks inside records are formatting, in both block modes.

The [records guide](records.md) explains copying from multiple objects, nesting, argument grouping, PHP integration, invalid forms, and snapshots in detail.

## Named steps and failures

```procer
for each job in jobs do
    step "Perform the calculation" do
        let result be calculate(job).
    on failure as problem do
        log(message of problem).
        next job.
```

A step's name is a string literal. Its optional `on failure as name do` handler aligns with the step in indentation mode. In `done` mode, one `done` closes the entire step, including its handler:

<!-- verify:procer done -->
```procer
step "Perform the calculation" do
    let result be calculate(input).
on failure as problem do
    log(message of problem).
done
```

The complete `on failure as name do` header is required. Other `on ...` forms keep their existing object-call meaning: for example, `on failure do cleanup.` calls `cleanup` on an object named `failure`; it does not declare a handler.

On success, the handler is skipped. A recoverable `OperationFailure` jumps to the nearest active handler and skips the rest of the failed body. Execution continues after the step when the handler finishes. A failure inside a handler goes to an outer handler, if present. Steps without handlers still supply diagnostic names. Steps do not introduce a variable scope; the failure variable temporarily shadows a variable in the current scope and is restored or removed when the handler exits, including on `return` or `next`. To retain the diagnostic, assign it to another variable.

See [steps and recovery](workflows.md) for failure fields, nesting, cleanup, and pausing. See [error handling](error_handling.md#recoverable-operation-failures-in-procer) for the provider contract and PHP-side retries.

## Continuing a named loop

`next item.` skips the rest of the current iteration of an enclosing `for each item` or `from ... as item` loop and advances it normally. It can leave nested loops and steps. Steps surrounding the target loop remain active; exited step handlers release their temporary failure bindings.

The nearest enclosing loop with that name is selected. The target must be in the same procedure. Unknown targets, jumps into other procedures, and targets that only name ordinary variables are compile errors. Anonymous ranges and `while`/`until` loops do not introduce target names, although `next` inside them can target an enclosing named loop.

Contextual keyword names remain legal loop names. `next on.` targets a loop named `on`, while `next on object.` retains the existing provider-method call syntax.
