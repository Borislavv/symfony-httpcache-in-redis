<?php

namespace App\Infrastructure\Http\Cache\Store;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Toflar\Psr6HttpCacheStore\Psr6Store;

class RedisStore extends Psr6Store implements RedisStoreInterface
{
    private array $locks = [];

    public function __construct(
        private readonly AdapterInterface $redis,
        private readonly string $cacheDir,
        private readonly LockFactory $lockFactory,
        private readonly PersistingStoreInterface $store,
        private readonly LoggerInterface $appLogger,
    ) {
        parent::__construct(
            [
                'cache' => $this->redis,
                'cache_directory' => $this->cacheDir,
                'lock_factory' => $this->lockFactory,
            ]
        );
    }

    public function lock(Request $request): bool|string
    {
        $key = new Key($this->getCacheKey($request));

        if ($this->store->exists($key)) {
            return false;
        }

        $lock = $this->lockFactory->createLockFromKey($key);

        $this->locks[$key->__toString()] = $lock;

        return $lock->acquire();
    }

    public function unlock(Request $request): bool
    {
        $key = new Key($this->getCacheKey($request));

        if (!$this->store->exists($key)) {
            return false;
        }

        try {
            ($this->locks[$key->__toString()] ?? null)?->release();
        } catch (LockReleasingException $e) {
            $this->appLogger
                ->emergency(
                    message: sprintf('[RedisStore] Lock releasing failed for key %s.', $key->__toString()),
                    context: ['$e' => $e->getMessage(), '$key' => $key->__toString()]
                );

            return false;
        } finally {
            unset($this->locks[$key->__toString()]);
        }

        return true;
    }

    public function isLocked(Request $request): bool
    {
        $key = new Key($this->getCacheKey($request));

        if (!$this->store->exists($key)) {
            return false;
        }

        return ($this->locks[$key->__toString()] ?? null)?->isAcquired() ?? false;
    }

    public function cleanup(): void
    {
        try {
            foreach ($this->locks as $key => $lock) {
                try {
                    $lock->release();
                } catch (LockReleasingException $e) {
                    /* @var Key $key */
                    $this->appLogger
                        ->emergency(
                            message: sprintf('[RedisStore] Lock releasing failed for key %s.', $key->__toString()),
                            context: ['$e' => $e->getMessage(), '$key' => $key->__toString()]
                        );
                }
            }
        } finally {
            $this->locks = [];
        }
    }
}
