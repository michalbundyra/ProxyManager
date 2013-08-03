--TEST--
Verifies that generated lazy loading value holders disallow private property direct isset check
--FILE--
<?php

require_once __DIR__ . '/init.php';

class Kitchen
{
    private $sweets;
}

$factory = new \ProxyManager\Factory\LazyLoadingValueHolderFactory($configuration);

$proxy = $factory->createProxy('Kitchen', function () {});

isset($proxy->sweets);
?>
--EXPECTF--
Fatal error: Cannot access private property %s::$sweets in %s on line %d