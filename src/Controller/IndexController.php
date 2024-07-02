<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
    #[Route('/', name: 'app_index')]
    #[Cache(maxage: 300, smaxage: 360, public: true, mustRevalidate: true, staleWhileRevalidate: 180, staleIfError: 86400)]
    public function index(): JsonResponse
    {
        sleep(5);

        return new JsonResponse([
            'data' => [
                'isSuccess' => true
            ]
        ]);
    }
}