<?php

namespace App\Infrastructure\Http\Cache\Store\Symfony;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Toflar\Psr6HttpCacheStore\Psr6Store;

class RedisStore extends Psr6Store implements RedisStoreInterface
{
    public function __construct(
        private readonly AdapterInterface $redis,
        private readonly string $cacheDir,
    ) {
        parent::__construct(
            [
                'cache' => $this->redis,
                'cache_directory' => $this->cacheDir, // cache dir is required but not using
            ]
        );
    }
}
