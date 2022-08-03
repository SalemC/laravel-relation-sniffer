# laravel-relation-sniffer

A simple way to sniff model relations, providing you with database-specific data.

## Basic Example

```php
use LaravelRelationSniffer\LaravelRelationSniffer;

$sniffer = new LaravelRelationSniffer();

$data = $sniffer->sniff();

dd($data);
```
