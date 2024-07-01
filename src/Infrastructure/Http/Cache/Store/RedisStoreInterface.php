<?php

namespace App\Infrastructure\Http\Cache\Store;

use Toflar\Psr6HttpCacheStore\ClearableInterface;
use Toflar\Psr6HttpCacheStore\Psr6StoreInterface;

interface RedisStoreInterface extends Psr6StoreInterface, ClearableInterface
{
}
