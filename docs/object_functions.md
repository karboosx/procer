# Object Functions

Object functions let you call PHP methods on objects that live in Procer variables. They use `ObjectFunctionProviderInterface` and are the counterpart to `FunctionProviderInterface` for plain functions.

## Calling object functions in Procer

There are two equivalent syntaxes:

```procer
// prefix style
on cart add("apple").
on cart do checkout().
on cart run clear.         // no parentheses when no arguments

// postfix style
add("apple") on cart.
checkout() on cart.
clear on cart.
```

The optional verbs `do` and `run` are purely cosmetic — they have no effect on execution and exist only to make the code read more naturally.

## Defining an object function provider

Implement `\Karboosx\Procer\ObjectFunctionProviderInterface`:

```php
use Karboosx\Procer\Context;
use Karboosx\Procer\ObjectFunctionProviderInterface;

class CartFunctionProvider implements ObjectFunctionProviderInterface
{
    public function supports(object $object, string $functionName): bool
    {
        return $object instanceof Cart
            && in_array($functionName, ['add', 'remove', 'checkout', 'clear']);
    }

    public function add(Context $context, Cart $cart, string $item): void
    {
        $cart->addItem($item);
    }

    public function remove(Context $context, Cart $cart, string $item): void
    {
        $cart->removeItem($item);
    }

    public function checkout(Context $context, Cart $cart): string
    {
        return $cart->processCheckout();
    }

    public function clear(Context $context, Cart $cart): void
    {
        $cart->clear();
    }
}
```

Key points:
- `supports(object $object, string $functionName)` receives the **actual object instance** as well as the function name, so you can match by class type, by function name, or both.
- Each method receives `Context` as its first argument and the **object** as its second, followed by any arguments passed from Procer.
- The return value is available to Procer if the call is used in an expression (`let result be checkout() on cart.`).

## Registering the provider

Pass it to the `Procer` constructor the same way as a plain function provider:

```php
$procer = new \Karboosx\Procer\Procer([
    new CartFunctionProvider(),
]);

$cart = new Cart();

$result = $procer->run(<<<CODE
on cart add("apple").
on cart add("banana").
let total be checkout() on cart.
CODE, ['cart' => $cart]);

echo $result->get('total');
```

## Using return values

When an object function returns a value you can assign it with `let`:

```procer
let order_id be checkout() on cart.
let count be item_count on cart.    // no parentheses is fine too
```

## Interrupts from object functions

Object functions can return an `Interrupt` exactly like plain functions:

```php
public function pay(Context $context, Cart $cart): string|Interrupt
{
    if (!$this->paymentGateway->isReady()) {
        return new Interrupt(InterruptType::BEFORE_EXECUTION);
    }

    return $this->paymentGateway->charge($cart->total());
}
```

See [Interrupts](interrupts.md) for details.

## Combining plain and object function providers

You can register as many providers as you like — both plain and object providers can coexist:

```php
$procer = new \Karboosx\Procer\Procer([
    new MathFunctionProvider(),   // FunctionProviderInterface
    new CartFunctionProvider(),   // ObjectFunctionProviderInterface
    new UserFunctionProvider(),   // ObjectFunctionProviderInterface
]);
```

## `supports()` resolution order

When Procer encounters `on cart add("apple")`, it iterates the providers in registration order and calls `supports($cartObject, 'add')` on each one. The **first** provider that returns `true` handles the call. If none does, an `ObjectFunctionNotFoundException` is thrown.

This means you can have multiple providers for the same function name, each targeting a different object type — they will be matched by the `supports()` check.
