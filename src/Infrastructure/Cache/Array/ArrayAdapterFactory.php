<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache\Array;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ArrayAdapterFactory
{
    public function __construct(
        private readonly mixed $maxItems,
        private readonly mixed $maxLifetime,
    ){}

    public function create(): CacheItemPoolInterface
    {
        return new ArrayAdapter(maxLifetime: (int) $this->maxLifetime, maxItems: (int) $this->maxItems);
    }
}
