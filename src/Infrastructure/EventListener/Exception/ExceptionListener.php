<?php

namespace App\Infrastructure\EventListener\Exception;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

class ExceptionListener
{
    public function __construct(private readonly LoggerInterface $appLogger)
    {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->appLogger->emergency('Unhandled throwable: ' . $event->getThrowable()->getMessage());
    }
}