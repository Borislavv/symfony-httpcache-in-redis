<?php

namespace App\Infrastructure\Runtime;

use App\Infrastructure\Runtime\Runner\Runner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

class Runtime extends SymfonyRuntime
{
    public function __construct(array $options)
    {
        parent::__construct($options);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof KernelInterface) {
            return new Runner($application, Request::createFromGlobals(), $this->options['debug'] ?? false);
        }

        return parent::getRunner($application);
    }
}
