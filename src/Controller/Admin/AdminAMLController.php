<?php

namespace App\Controller\Admin;

use App\Entity\Wallet\Wallet;
use App\Service\AML\AMLDecisionEngineService;
use App\Service\AnomalyDetectionService;
use App\Service\RiskScoringService;
use App\Service\WalletAnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/aml', name: 'admin_aml_')]
class AdminAMLController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface   $entityManager,
        private readonly AMLDecisionEngineService $amlEngine,
        private readonly WalletAnalyticsService   $analyticsService,
        private readonly AnomalyDetectionService  $anomalyService,
        private readonly RiskScoringService       $riskService,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        /** @var Wallet[] $wallets */
        $wallets = $this->entityManager->getRepository(Wallet::class)->findAll();

        $summaries = [];
        foreach ($wallets as $wallet) {
            $summaries[] = $this->amlEngine->summarizeWallet($wallet);
        }

        usort($summaries, static fn($a, $b) => $b['score'] <=> $a['score']);

        $stats = [
            'total'                => count($summaries),
            'critique'             => count(array_filter($summaries, static fn($s) => $s['level'] === 'critique')),
            'eleve'                => count(array_filter($summaries, static fn($s) => $s['level'] === 'eleve')),
            'moyen'                => count(array_filter($summaries, static fn($s) => $s['level'] === 'moyen')),
            'faible'               => count(array_filter($summaries, static fn($s) => $s['level'] === 'faible')),
            'with_alerts'          => count(array_filter($summaries, static fn($s) => $s['alert_count'] > 0)),
            'critical_alerts_total'=> array_sum(array_column($summaries, 'critical_count')),
            'high_alerts_total'    => array_sum(array_column($summaries, 'high_count')),
        ];

        return $this->render('admin/aml/dashboard.html.twig', [
            'summaries' => $summaries,
            'stats'     => $stats,
        ]);
    }

    #[Route('/wallet/{id}', name: 'wallet_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function walletDetail(int $id): Response
    {
        $wallet = $this->entityManager->getRepository(Wallet::class)->find($id);
        if (!$wallet) {
            throw $this->createNotFoundException('Wallet introuvable.');
        }

        $analytics     = $this->analyticsService->buildAnalytics($wallet);
        $anomalyReport = $this->anomalyService->detectAnomalies($wallet, $analytics);
        $riskAnalysis  = $this->riskService->scoreWallet($wallet, $analytics, $anomalyReport);
        $amlAnalysis   = $this->amlEngine->analyzeWallet($wallet, $analytics, $anomalyReport, $riskAnalysis);

        return $this->render('admin/aml/wallet_detail.html.twig', [
            'wallet'      => $wallet,
            'amlAnalysis' => $amlAnalysis,
            'analytics'   => $analytics,
            'riskAnalysis'=> $riskAnalysis,
        ]);
    }
}
