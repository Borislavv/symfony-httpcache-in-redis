<?php

namespace App\Infrastructure\Runtime;

use App\Infrastructure\Runtime\Runner\CustomHttpCacheRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

class CustomHttpCacheRuntime extends GenericRuntime
{
    public function __construct(array $options)
    {
        parent::__construct($options);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof KernelInterface) {
            return new CustomHttpCacheRunner(
                $application,
                Request::createFromGlobals(),
                $this->options['debug'] ?? false
            );
        }

        return parent::getRunner($application);
    }
}
