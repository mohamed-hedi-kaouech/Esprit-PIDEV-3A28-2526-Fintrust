<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Service\MarketPredictorService;
use App\Service\MarketSentimentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/market-predictions', name: 'api_market_predictions_')]
class MarketPredictionApiController extends AbstractController
{
    public function __construct(
        private readonly MarketPredictorService $marketPredictorService,
        private readonly MarketSentimentService $marketSentimentService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $wallet = $this->findLatestWalletForUser($this->getAuthenticatedUser());
        $marketData = $this->marketPredictorService->buildMarketPredictions($wallet);
        $global = $this->marketSentimentService->buildSummary($marketData, $wallet);

        return $this->json(array_merge($marketData, $global));
    }

    #[Route('/wallet/{id}', name: 'wallet', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function wallet(int $id): JsonResponse
    {
        $wallet = $this->findOwnedWallet($this->getAuthenticatedUser(), $id);
        if (!$wallet instanceof Wallet) {
            return $this->json(['error' => 'wallet_not_found'], 404);
        }

        $marketData = $this->marketPredictorService->buildMarketPredictions($wallet);
        $global = $this->marketSentimentService->buildSummary($marketData, $wallet);

        return $this->json(array_merge($marketData, $global));
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function findLatestWalletForUser(User $user): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.dateCreation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $wallet;
    }

    private function findOwnedWallet(User $user, int $walletId): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.idWallet = :walletId')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('walletId', $walletId)
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $wallet;
    }
}
