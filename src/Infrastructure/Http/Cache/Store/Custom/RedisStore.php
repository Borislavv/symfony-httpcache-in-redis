<?php

namespace App\Infrastructure\Http\Cache\Store\Custom;

use RedisException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\Lock\LockFactory;

class RedisStore implements RedisStoreInterface
{
    /**
     * @param \SplObjectStorage<Request, string> $keyCache
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly LockFactory $lockFactory,
        private readonly int $ttl = 3600,
        private readonly bool $useLocks = false,
        private \SplObjectStorage $keyCache = new \SplObjectStorage(),
        private array $options = []
    ) {
        $this->options = array_merge([
            'private_headers' => ['Set-Cookie'],
        ], $options);
    }

    /**
     * @throws \RedisException
     */
    public function lookup(Request $request): ?Response
    {
        $key = $this->getCacheKey($request);

        var_dump("INTO LOOK UP");

        if (!$entries = $this->getMetadata($key)) {
            return null;
        }

        // find a cached entry that matches the request.
        $match = null;
        foreach ($entries as $entry) {
            if ($this->requestsMatch(isset($entry[1]['vary'][0]) ? implode(', ', $entry[1]['vary']) : '', $request->headers->all(), $entry[0])) {
                $match = $entry;

                break;
            }
        }

        if (null === $match) {
            var_dump("DID NOT MATCHED");
            return null;
        }

        $headers = $match[1];
        $digest = $headers['x-content-digest'][0];
        var_dump("DIGEST: " . $digest);
        $isDigestExists = $this->redis->exists($digest);
        var_dump("IS FOUND DIGEST", $isDigestExists);
        if ($isDigestExists === 1 || $isDigestExists === true) {
            $resp = $this->restoreResponse($headers, $digest);
            var_dump("RESPONSE RESTORED FROM CACHE");
            return $resp;
        }

        var_dump("DIGEST NOT FOUND IN CACHE");

        // TODO the metaStore referenced an entity that doesn't exist in
        // the entityStore. We definitely want to return nil but we should
        // also purge the entry from the meta-store when this is detected.
        return null;
    }

    /**
     * @throws \RedisException
     */
    public function write(Request $request, Response $response): string
    {
        $key = $this->getCacheKey($request);
        $storedEnv = $this->persistRequest($request);

        if ($response->headers->has('X-Body-File')) {
            // Assume the response came from disk, but at least perform some safeguard checks
            if (!$response->headers->has('X-Content-Digest')) {
                throw new \RuntimeException('A restored response must have the X-Content-Digest header.');
            }

            if ($response->headers->get('X-Content-Digest') !== $response->headers->get('X-Body-File')) {
                throw new \RuntimeException('X-Body-File and X-Content-Digest do not match.');
            }
            // Everything seems ok, omit writing content to disk
        } else {
            $digest = $this->generateContentDigest($response);
            $response->headers->set('X-Content-Digest', $digest);

            if (!$this->save($digest, $response->getContent(), false)) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', \strlen($response->getContent()));
            }

            var_dump('DIGEST SAVED TO CACHE');
        }

        // read existing cache entries, remove non-varying, and add this one to the list
        $entries = [];
        $vary = $response->headers->get('vary');
        foreach ($this->getMetadata($key) as $entry) {
            if (!isset($entry[1]['vary'][0])) {
                $entry[1]['vary'] = [''];
            }

            if ($entry[1]['vary'][0] != $vary || !$this->requestsMatch($vary ?? '', $entry[0], $storedEnv)) {
                $entries[] = $entry;
            }
        }

        $headers = $this->persistResponse($response);
        unset($headers['age']);

        foreach ($this->options['private_headers'] as $h) {
            unset($headers[strtolower($h)]);
        }

        array_unshift($entries, [$storedEnv, $headers]);

        if (!$this->save($key, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }

        var_dump('REQUEST SAVED TO CACHE');

        return $key;
    }

    /**
     * @throws \RedisException
     */
    private function save(string $key, string $data, bool $overwrite = true): bool
    {
        if ($overwrite) {
            return true === $this->redis->set(key: $key, value: $data, options: ['ex' => $this->ttl]);
        }

        return true === $this->redis->set(key: $key, value: $data, options: ['nx', 'ex' => $this->ttl]);
    }

    /**
     * @throws RedisException
     */
    public function invalidate(Request $request)
    {
        $this->redis->del($this->getCacheKey($request));
    }

    public function lock(Request $request): bool|string
    {
        return $this->lockFactory
            ->createLock($this->getCacheKey($request).'_lock', autoRelease: true)
            ->acquire();
    }

    public function unlock(Request $request): bool
    {
        $this->lockFactory
            ->createLock($this->getCacheKey($request).'_lock', autoRelease: true)
            ->release();

        return true;
    }

    public function isLocked(Request $request): bool
    {
        $this->lockFactory
            ->createLock($this->getCacheKey($request).'_lock', autoRelease: true)
            ->isAcquired();

        return true;
    }

    public function purge(string $url): bool
    {
        return true;
    }

    public function cleanup()
    {
        return;
    }

    protected function generateCacheKey(Request $request): string
    {
        return 'md'.hash('sha256', $request->getUri());
    }

    protected function generateContentDigest(Response $response): string
    {
        return 'en'.hash('xxh128', $response->getContent());
    }

    private function persistRequest(Request $request): array
    {
        return $request->headers->all();
    }

    /**
     * Returns a cache key for the given Request.
     */
    private function getCacheKey(Request $request): string
    {
        if (isset($this->keyCache[$request])) {
            return $this->keyCache[$request];
        }

        return $this->keyCache[$request] = $this->generateCacheKey($request);
    }

    /**
     * Gets all data associated with the given key.
     *
     * Use this method only if you know what you are doing.
     *
     * @throws \RedisException
     */
    private function getMetadata(string $key): array
    {
        if (!$entries = $this->load($key)) {
            return [];
        }

        return unserialize($entries) ?: [];
    }

    /**
     * @throws \RedisException
     */
    private function load(string $key): ?string
    {
        $data = $this->redis->get($key);

        if (!is_string($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @throws \RedisException
     */
    private function restoreResponse(array $headers, string $digest = null): ?Response
    {
        $status = $headers['X-Status'][0];
        unset($headers['X-Status']);
        $content = null;

        if (null !== $digest) {
            $headers['X-Body-File'] = [$digest];
            unset($headers['x-body-file']);

            if ($headers['X-Body-Eval'] ?? $headers['x-body-eval'] ?? false) {
                $content = $this->redis->get($digest);
                if (!is_string($content)) {
                    return null;
                }
                \assert(HttpCache::BODY_EVAL_BOUNDARY_LENGTH === 24);
                if (48 > \strlen($content) || substr($content, -24) !== substr($content, 0, 24)) {
                    return null;
                }
            }
        }

        var_dump('RETURNED FROM CACHE');

        return new Response($content, $status, $headers);
    }

    private function requestsMatch(?string $vary, array $env1, array $env2): bool
    {
        if (empty($vary)) {
            return true;
        }

        foreach (preg_split('/[\s,]+/', $vary) as $header) {
            $key = str_replace('_', '-', strtolower($header));
            $v1 = $env1[$key] ?? null;
            $v2 = $env2[$key] ?? null;
            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;
    }

    private function persistResponse(Response $response): array
    {
        $headers = $response->headers->all();
        $headers['X-Status'] = [$response->getStatusCode()];

        return $headers;
    }
}
