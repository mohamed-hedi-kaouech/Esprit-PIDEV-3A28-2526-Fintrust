<?php

namespace App\Controller\Api;

use App\Service\EconomicDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/economy', name: 'api_economy_')]
#[IsGranted('ROLE_CLIENT')]
class EconomyController extends AbstractController
{
    public function __construct(
        private readonly EconomicDataService $economicDataService,
    ) {}

    #[Route('/overview', name: 'overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return $this->json($this->economicDataService->getOverview());
    }

    #[Route('/inflation', name: 'inflation', methods: ['GET'])]
    public function inflation(): JsonResponse
    {
        return $this->json($this->economicDataService->getInflation());
    }

    #[Route('/rates', name: 'rates', methods: ['GET'])]
    public function rates(): JsonResponse
    {
        return $this->json($this->economicDataService->getRates());
    }

    #[Route('/currencies', name: 'currencies', methods: ['GET'])]
    public function currencies(): JsonResponse
    {
        return $this->json($this->economicDataService->getCurrencies());
    }

    #[Route('/indicators', name: 'indicators', methods: ['GET'])]
    public function indicators(): JsonResponse
    {
        return $this->json($this->economicDataService->getIndicators());
    }
}
