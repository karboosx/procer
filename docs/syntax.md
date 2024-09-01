# Syntax
Let's start with the basics. The syntax of the language is very simple and easy to understand. It is designed to be as close to natural language as possible. Here is a simple example of a program that assigns a value to a variable and sends it to the console function.
```
let x be "Hello, World!".
on console print(x).
```

**Remember!**
> Each statement must end with a period!

### Indentation
The language relies on indentation to define blocks of code. Each block of code must have the same level of indentation. Here is an example:
```
if x is "Hello, World!" do
    on console print("x is Hello, World!").
```

If you don't want to use indentation, yuo can set special flag in the `Procer` class to disable it. Here is an example:
```php
$procer = new Procer();
$procer->useDoneKeyword();
```

With this flag set, you can write the code like this:
```
if x is "Hello, World!" do on console print("x is Hello, World!"). done
```

or like this:
```
if x is "Hello, World!" do
    on console print("x is Hello, World!").
done
```

As you can see, the `done` keyword is used to close the block of code.

## Assigning values
To assign a value to a variable, you can use the `let` keyword followed by the variable name, the `be` keyword, and the value you want to assign to the variable. The value can be a string, a number, boolean, or a function. Here are some examples:
```
let x be "Hello, World!".
let y be 42.
let z be true.
let w be add(1, 2).
```

## Calling functions
To call a function, you can use the function name followed by the arguments in parentheses. If the function returns a value, you can assign it to a variable using the `let` keyword.
```
some_function().
function_with_arguments("argument A", "argument B").
let x be func().
let y be add(3, 4).
```

### Calling functions on objects

If you want to call a function on an object, you can use the `on` keyword followed by the object name. Here are some examples:
```
on shopping_cart add("apple").
on file delete().
```

When calling a function on an object, you can prefix the function name with a any verb to make the code more readable. For example:
```
on user_account do confirm().
on file run delete().
```

Also, when calling a function on an object, you can drop the parentheses if the function does not take any arguments. For example:
```
on user_account do logout.
on file run delete.
```

### Reverse order of function call

If you prefer to write the function call before the object, you can do that like this:
```
add("apple") on shopping_cart.
delete() on file.
```

> **Note:** In this case, you cannot drop the parentheses even if the function does not take any arguments.

## Conditional statements
To create a conditional statement, you can use the `if` keyword followed by the condition you want to check. If the condition is true, the code inside the block will be executed. Here is an example:
```
if x is "Hello, World!" do
    on console print("x is Hello, World!").
```

You can also use the `if not` keyword to execute code when the previous condition is false. Here is an example:
```
if x is "Hello, World!" do
    on console print("x is Hello, World!").
if not do
    on console print("x is not Hello, World!").
```

If you want to check multiple conditions, you can use the `or` keyword. Here is an example:
```
if x is "Hello, World!" do
    on console print("x is Hello, World!").
or x is "Goodbye, World!" do
    on console print("x is Goodbye, World!").
```

## Loops

There are three types of loops in the language: `from`, `for each`, and `while`. Here are some examples:
```
from 1 to 10 do
    on console print("doing a loop").
```

If you want to access the current index in the loop, you can use the `as` keyword. Here is an example:
```
from 1 to 10 as i do
    on console print(i).
```

To iterate over a list, you can use the `for each` loop. Here is an example:
```
for each item in list do
    on console print(item).
```

To create a loop that runs while a condition is true, you can use the `while` or `until` loop. Here is an example:
```
let i be 0.
while i < 10 do
    on console print(i).
    let i be i + 1.
```

## Stop execution
To stop the execution of the program, you can use the `stop` keyword. Here is an example:
```
if x is "Hello, World!" do
    stop.
```

Program will be halted and can be resumed by running the `Procer::resume($state)` from the php side.

> **Note:** Resumed program will start right after the `stop` statement.

