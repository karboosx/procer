# Security

## Limiting the number of cycles

The number of cycles can be limited by setting the `max_cycles` parameter by using the `Karboosx\Procer::setMaxCycles($maxCycles)` method. Here is an example:

```php
$procer = new Karboosx\Procer();

$procer->setMaxCycles(10);
```

### Unlimited cycles

If you want to run the business logic without any cycle limit, you can set the `max_cycles` parameter to `-1`. Here is an example:

```php
$procer = new Karboosx\Procer();

$procer->setMaxCycles(-1);
```

## User submitted scripts

If you are running user submitted scripts, you should be careful about the security implications. 
You should always allow only safe function providers and set the `max_cycles` parameter to a reasonable value.