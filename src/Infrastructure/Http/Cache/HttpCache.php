<?php

namespace App\Infrastructure\Http\Cache;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\HttpCache as SymfonyHttpCache;
use Symfony\Component\HttpKernel\HttpCache\ResponseCacheStrategyInterface;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;
use Symfony\Component\HttpKernel\HttpCache\SurrogateInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpCache extends SymfonyHttpCache implements HttpCacheInterface
{
    private Request $request;
    private array $traces = [];
    protected ResponseCacheStrategyInterface|null $surrogateCacheStrategy = null;

    public function __construct(
        private readonly \Redis $redis,
        HttpKernelInterface $kernel,
        StoreInterface $store,
        protected SurrogateInterface|null $surrogate = null,
        protected array $options = [],
    ) {
        $this->options = array_merge([
            'debug' => false,
            'default_ttl' => 0,
            'private_headers' => ['Authorization', 'Cookie'],
            'allow_reload' => false,
            'allow_revalidate' => false,
            'stale_while_revalidate' => 2,
            'stale_if_error' => 60,
            'trace_level' => 'none',
            'trace_header' => 'X-Symfony-Cache',
            'terminate_on_cache_hit' => true,
        ], $options);

        if (!isset($options['trace_level'])) {
            $this->options['trace_level'] = $this->options['debug'] ? 'full' : 'none';
        }

        parent::__construct($kernel, $store, $surrogate, $options);
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        // FIXME: catch exceptions and implement a 500 error page here? -> in Varnish, there is a built-in error page mechanism
        if (HttpKernelInterface::MAIN_REQUEST === $type) {
            // Keep a clone of the original request for surrogates so they can access it.
            // We must clone here to get a separate instance because the application will modify the request during
            // the application flow (we know it always does because we do ourselves by setting REMOTE_ADDR to 127.0.0.1
            // and adding the X-Forwarded-For header, see HttpCache::forward()).
            $this->request = clone $request;
            if (null !== $this->surrogate) {
                $this->surrogateCacheStrategy = $this->surrogate->createCacheStrategy();
            }
        }

        $this->traces[$this->getTraceKey($request)] = [];

        if (!$request->isMethodSafe()) {
            $response = $this->invalidate($request, $catch);
        } elseif ($request->headers->has('expect') || !$request->isMethodCacheable()) {
            $response = $this->pass($request, $catch);
        } elseif ($this->options['allow_reload'] && $request->isNoCache()) {
            /*
                If allow_reload is configured and the client requests "Cache-Control: no-cache",
                reload the cache by fetching a fresh response and caching it (if possible).
            */
            $this->record($request, 'reload');
            $response = $this->fetch($request, $catch);
        } else {
            $response = $this->lookup($request, $catch);
        }

        $this->restoreResponseBody($request, $response);

        if (HttpKernelInterface::MAIN_REQUEST === $type) {
            $this->addTraces($response);
        }

        if (null !== $this->surrogate) {
            if (HttpKernelInterface::MAIN_REQUEST === $type) {
                $this->surrogateCacheStrategy->update($response);
            } else {
                $this->surrogateCacheStrategy->add($response);
            }
        }

        $response->prepare($request);

        $response->isNotModified($request);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        // Do not call any listeners in case of a cache hit.
        // This ensures identical behavior as if you had a separate
        // reverse caching proxy such as Varnish and the like.
        if ($this->options['terminate_on_cache_hit']) {
            trigger_deprecation('symfony/http-kernel', '6.2', 'Setting "terminate_on_cache_hit" to "true" is deprecated and will be changed to "false" in Symfony 7.0.');
        } elseif (\in_array('fresh', $this->traces[$this->getTraceKey($request)] ?? [], true)) {
            return;
        }

        if ($this->getKernel() instanceof TerminableInterface) {
            $this->getKernel()->terminate($request, $response);
        }
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getLog(): string
    {
        $log = [];
        foreach ($this->traces as $request => $traces) {
            $log[] = sprintf('%s: %s', $request, implode(', ', $traces));
        }

        return implode('; ', $log);
    }

    private function record(Request $request, string $event)
    {
        $this->traces[$this->getTraceKey($request)][] = $event;
    }

    /**
     * @throws \Exception
     * @throws \RedisException
     */
    private function restoreResponseBody(Request $request, Response $response)
    {
        if ($response->headers->has('X-Body-Eval')) {
            \assert(self::BODY_EVAL_BOUNDARY_LENGTH === 24);

            ob_start();

            $content = $response->getContent();
            $boundary = substr($content, 0, 24);
            $j = strpos($content, $boundary, 24);
            echo substr($content, 24, $j - 24);
            $i = $j + 24;

            while (false !== $j = strpos($content, $boundary, $i)) {
                [$uri, $alt, $ignoreErrors, $part] = explode("\n", substr($content, $i, $j - $i), 4);
                $i = $j + 24;

                echo $this->surrogate->handle($this, $uri, $alt, $ignoreErrors);
                echo $part;
            }

            $response->setContent(ob_get_clean());
            $response->headers->remove('X-Body-Eval');
            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', \strlen($response->getContent()));
            }
        } elseif ($response->headers->has('X-Body-File')) {
            // Response does not include possibly dynamic content (ESI, SSI), so we need
            // not handle the content for HEAD requests
            if (!$request->isMethod('HEAD')) {
                $digest = $this->redis->get($response->headers->get('X-Body-File'));
                if (is_string($digest) || is_null($digest)) {
                    $response->setContent($digest);
                }
            }
        } else {
            return;
        }

        $response->headers->remove('X-Body-File');
    }

    private function getTraceKey(Request $request): string
    {
        $path = $request->getPathInfo();
        if ($qs = $request->getQueryString()) {
            $path .= '?'.$qs;
        }

        return $request->getMethod().' '.$path;
    }

    private function addTraces(Response $response)
    {
        $traceString = null;

        if ('full' === $this->options['trace_level']) {
            $traceString = $this->getLog();
        }

        if ('short' === $this->options['trace_level'] && $masterId = array_key_first($this->traces)) {
            $traceString = implode('/', $this->traces[$masterId]);
        }

        if (null !== $traceString) {
            $response->headers->add([$this->options['trace_header'] => $traceString]);
        }
    }

    public function getTraces(): array
    {
        return $this->traces;
    }
}
