<?php

namespace App\Infrastructure\Http\Cache;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

interface HttpCacheInterface extends HttpKernelInterface, TerminableInterface
{

}
