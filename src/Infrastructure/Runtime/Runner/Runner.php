<?php

namespace App\Infrastructure\Runtime\Runner;

use Psr\Log\LoggerInterface;
use App\Infrastructure\Http\Cache\HttpCacheInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\Runner\Symfony\HttpKernelRunner;
use Symfony\Component\Runtime\RunnerInterface;

class Runner extends HttpKernelRunner implements RunnerInterface
{
    private LoggerInterface $appLogger;

    public function __construct(
        private readonly HttpKernelInterface $application,
        private readonly Request             $request,
        private readonly bool                $debug = false,
    ) {
        parent::__construct($this->application, $this->request, $this->debug);
    }

    public function run(): int
    {
        $this->application->boot();

        /** @var LoggerInterface $appLogger */
        $appLogger = $this->application->getContainer()->get('monolog.logger.errors');
        $this->appLogger = $appLogger;

        $this->setWarningHandlerForDestructedCoroutineContext($appLogger);

        $this->handle($this->request);

        return 0;
    }

    public function handle(Request $request): void
    {
        try {
            $response = $this->handleRequest($request);

            if ($this->application instanceof TerminableInterface) {
                $this->application->terminate($request, $response);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, $response ?? new Response());
        }
    }

    private function isHttpCacheEnabled(): bool
    {
        return $this->application->getContainer()->has(HttpCacheInterface::class) && !$this->debug;
    }

    private function handleException(\Throwable $e, Response $response): void
    {
        $this->appLogger
            ->emergency(
                message: '[CORE] EMERGENCY ERROR! '.$e->getMessage(),
                context: ['$e' => $e->getMessage(), '$trace' => $e->getTraceAsString()]
            );

        $reason = 'Internal server error.';
        $response->headers->set('Content-Type', 'application/json');
        $response->setStatusCode(SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR, $reason);
        $response->send(json_encode([
            'error' => [
                'code' => SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR,
                'message' => $reason,
            ],
        ]) ?: null);
    }

    /**
     * @throws \Exception
     */
    private function handleRequest(SymfonyRequest $request): Response
    {
        return $this->isHttpCacheEnabled()
            ? $this->application
                ->getContainer()
                ->get(HttpCacheInterface::class)
                ->handle($request)
            : $this->application
                ->handle($request);
    }

    private function setWarningHandlerForDestructedCoroutineContext(LoggerInterface $logger): void
    {
        set_error_handler(
            function ($errno, $errstr, $errfile, $errline) use ($logger): bool {
                $logger
                    ->error(
                        message: $errstr,
                        context: [
                            '$stacktrace' => $this->traceToString(debug_backtrace())
                        ]
                    );

                return true;
            },
        );
    }

    private function traceToString($trace): array
    {
        foreach ($trace as $i => $call) {
            if (array_key_exists('object', $call) && is_object($call['object'])) {
                $call['object'] = get_class($call['object']);
            }
            if (array_key_exists('args', $call) && is_array($call['args'])) {
                foreach ($call['args'] as &$arg) {
                    if (is_object($arg)) {
                        $arg = get_class($arg);
                    }
                }
            }

            $file = $call['file'] ?? '__UNDEFINED FILE__';

            $line = $call['line'] ?? '__UNDEFINED LINE__';

            $type = (
                !empty($call['object'])
                    ? ($call['object'].($call['type'] ?? '__UNDEFINED TYPE__'))
                    : ''
            );

            if (!array_key_exists('function', $call)) {
                $func = '__UNDEFINED FUNC__';
            } else {
                if (is_array($call['function'])) {
                    $func = implode(', ', $call['function']);
                } elseif (is_string($call['function'])) {
                    $func = $call['function'];
                } else {
                    $func = '__UNDEFINED FUNC__';
                }
            }

            if (!array_key_exists('args', $call)) {
                $args = '__UNDEFINED ARGS__';
            } else {
                if (is_array($call['args'])) {
                    $args = $this->convertArgsToString($call['args']);
                } elseif (is_string($call['args'])) {
                    $args = $call['args'];
                } else {
                    $args = '__UNDEFINED ARGS__';
                }
            }

            $traceText[$i] = '#'.$i.' '.$file.'('.$line.') ';
            $traceText[$i] .= $type;
            $traceText[$i] .= $func.'('.$args.')';
        }

        return $traceText ?? [];
    }

    private function convertArgsToString($args): string
    {
        if (is_object($args)) {
            return get_class($args);
        } elseif (is_array($args)) {
            $result = [];
            foreach ($args as $key => $value) {
                $result[] = $key.' => '.$this->convertArgsToString($value);
            }

            return implode(', ', $result);
        } elseif (is_string($args)) {
            return '"'.$args.'"';
        } elseif (is_bool($args)) {
            return $args ? 'true' : 'false';
        } elseif (is_null($args)) {
            return 'null';
        } else {
            return (string) $args;
        }
    }
}
