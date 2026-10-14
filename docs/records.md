# Records

[Documentation index](README.md) · [Syntax](syntax.md) · [Expressions](expressions.md)

A record is a new value with named fields. In PHP it is a `stdClass`. Constructing a record does not call a provider, instantiate a domain class, save data, or validate an application's business rules.

## Construct a record

```procer
let amount be 24.
let result be amount as "amount", "EUR" as "currency".
return result.
```

The PHP result has the shape `{"amount":24,"currency":"EUR"}`.

| Part | Meaning |
| --- | --- |
| `let result be` | Assign the expression to a variable named `result` |
| `amount` | Read the variable's current value |
| `as` | Give that value a field name in the new record |
| `"amount"` | A literal field name, not a function parameter or variable lookup |
| `,` | Continue the record with another field |
| `.` | Finish the statement |

The names are independent: `let result be amount as "total".` creates a field named `total`, using the value of `amount`. A single field still creates a record; it does not rename the variable.

## Copy selected fields from another object

This fragment assumes `customer` is a PHP object with readable `name` and `email` fields:

```procer
let contact be
    name of customer as "display_name",
    email of customer as "email",
    true as "active".
```

Every expression before `as` supplies its own value. The source object is not the starting point of an implicit merge: only the listed fields are present. The new record can combine fields from several objects, ordinary variables, literals, calculations, or provider results.

For example, with readable `customer` and `project` objects and a `total` variable:

```procer
let summary be
    name of customer as "customer_name",
    title of project as "project_title",
    total as "amount".
```

`summary` is a new object. The first two values come from different objects, and the third comes from a variable. Each quoted name describes the destination field independently of its source.

The existing `of` rules apply when reading a source: public property first, then public zero-required-argument method/getter fallbacks. Missing source data raises an error; a new field is not silently populated with `null`.

## Read fields

```procer
let result be 24 as "amount", "EUR" as "currency".
let total be amount of result.
return total.
```

PHP reads the same record as `$record->amount`. Property paths also work through nested records: `name of customer of result`.

Field names that are not identifiers, such as `"display name"`, need a host-provided accessor or PHP's `$record->{'display name'}`. There is no bracket-access syntax in Procer.

## Nested records

Parentheses mark the boundary of an inner record:

```procer
let result be
    24 as "amount",
    ("Ada" as "name", "ada@example.test" as "email") as "customer".
return name of customer of result.
```

This returns `"Ada"`. Without the inner parentheses, commas belong to the outer record.

## Function arguments

The clearest form gives the record a name first. This fragment requires an application `save` function:

```procer
let result be 24 as "amount", "EUR" as "currency".
save(result).
```

An inline record needs its own parentheses inside the call:

```procer
save((24 as "amount", "EUR" as "currency")).
```

The outer parentheses delimit the function's argument list; the inner ones delimit one record argument. `save(24 as "amount", "EUR" as "currency").` is invalid. Multiple record arguments each need grouping:

```procer
compare((1 as "value"), (2 as "value")).
```

The same rule applies to procedure arguments and object calls. This syntax does not introduce named function arguments.

## Where records can appear

| Context | Form |
| --- | --- |
| Assignment | `let result be 1 as "value".` |
| Procedure or main-script return | `return 1 as "value".` |
| PHP expression API | `$procer->runExpression('1 as "value"')` |
| Function argument | `accept((1 as "value")).` |
| Inner field | `let result be (1 as "value") as "nested".` |
| Other surrounding expression | Group the record with parentheses; the surrounding operation must accept an object |

An expression passed to `runExpression()` has no final period. Records can be compared using the existing loose comparison rules, but numeric arithmetic on records raises a runtime error.

## Validation and evaluation

- Field names must be double-quoted string literals. Dynamic labels such as `value as field_name` are not supported.
- Names are case-sensitive. `"name"` and `"Name"` are different fields.
- Duplicate decoded names are rejected during compilation. Later fields do not overwrite earlier ones.
- Empty names, numeric-looking names, spaces, escaped quotes/backslashes, and Unicode are accepted. NUL bytes are rejected.
- At least one field is required. There is no empty-record literal, trailing comma, spread operator, implicit merge, `{}` literal, or `[]` literal.
- Field values evaluate left to right. Their internal expressions retain the existing precedence and function-argument evaluation rules.
- Assignment happens after every field has been evaluated. If a later field fails, the destination keeps its previous value, or remains undefined if it had none.
- Earlier provider effects and assignments are not rolled back. A handled failure discards the incomplete record's operands; an unhandled failure preserves operands for a possible host retry.

Line breaks inside the expression are formatting in both block modes. The final period still ends the whole assignment.

## Objects, arrays, and references

A record's fields may hold any values supplied by the language or providers, including arrays and domain objects. There is no automatic deep copy. Object-valued fields refer to the same object during execution, so a provider mutation of that object remains visible through the record.

Arrays are supplied by PHP/providers and can be traversed with `for each`. A record itself is an object, not an iterable PHP array; pass an array when using `for each`.

Native construction does not replace domain validation. A provider accepting a record should check required fields, types, and permissions before performing an operation. [Custom functions](custom_functions.md#accepting-records) shows that boundary.

## Saving records

Records use the existing `stdClass` snapshot encoding. Their contents must also be serializable. Resources, unsupported domain objects, invalid UTF-8, cycles, and values exceeding configured structural limits can make serialization fail.

Snapshots preserve values, not a general-purpose PHP object identity graph. Do not depend on two ordinary object references remaining identical with `===` after restoration. Use application object IDs when identity matters. See [Serialization](serialization.md).
