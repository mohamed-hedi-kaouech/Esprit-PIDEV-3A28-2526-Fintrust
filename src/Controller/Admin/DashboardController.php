<?php

namespace App\Controller\Admin;

use App\Entity\User\User;
use App\Repository\KycRepository;
use App\Repository\UserRepository;
use App\Service\AdvancedAnalyticsService;
use App\Service\ComplianceCopilotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur Admin — Tableau de bord (BackOffice)
 *
 * Affiche les statistiques globales de la plateforme FinTrust.
 * Accès restreint à ROLE_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin', name: 'admin_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly KycRepository  $kycRepository,
        private readonly ComplianceCopilotService $complianceCopilotService,
        private readonly AdvancedAnalyticsService $advancedAnalyticsService,
    ) {}

    /**
     * Dashboard principal : statistiques, KYC en attente, graphique mensuel.
     */
    #[Route('/tableau-de-bord', name: 'dashboard')]
    public function index(): Response
    {
        $stats = $this->userRepository->getStats();
        $riskBreakdown = $this->userRepository->getRiskBreakdown();
        $topRiskUsers = $this->userRepository->getTopRiskUsers();
        $systemHealth = $this->userRepository->getSystemHealth();
        $monthly = $this->userRepository->getMonthlyRegistrations();
        $weekly = $this->userRepository->getWeeklyRegistrations();
        $pendingKyc = $this->kycRepository->findPending();
        $kycBreakdown = $this->kycRepository->countByStatut();
        $clients = $this->userRepository->findBy(
            ['role' => 'CLIENT'],
            ['createdAt' => 'DESC'],
            50
        );
        $copilotUsers = [];

        foreach (array_slice($pendingKyc, 0, 3) as $kyc) {
            if ($kyc->getUser() instanceof User) {
                $copilotUsers[$kyc->getUser()->getId()] = $kyc->getUser();
            }
        }

        foreach ($topRiskUsers as $item) {
            if (count($copilotUsers) >= 3) {
                break;
            }

            if (($item['user'] ?? null) instanceof User) {
                /** @var User $riskUser */
                $riskUser = $item['user'];
                $copilotUsers[$riskUser->getId()] = $riskUser;
            }
        }

        foreach ($clients as $client) {
            if (count($copilotUsers) >= 3) {
                break;
            }

            if ($client instanceof User) {
                $copilotUsers[$client->getId()] = $client;
            }
        }

        $complianceCases = array_map(
            fn(User $user): array => $this->complianceCopilotService->generateReview($user),
            array_values(array_slice($copilotUsers, 0, 3, true))
        );

        return $this->render('admin/dashboard.html.twig', [
            'stats'      => $stats,
            'monthly'    => $monthly,
            'weekly'     => $weekly,
            'riskBreakdown' => $riskBreakdown,
            'topRiskUsers' => $topRiskUsers,
            'systemHealth' => $systemHealth,
            'kycBreakdown' => $kycBreakdown,
            'clients'    => $clients,
            'pendingKyc' => $pendingKyc,
            'complianceCases' => $complianceCases,
            'complianceSamples' => $this->complianceCopilotService->getSampleCases(),
            'advancedOverview' => $this->advancedAnalyticsService->getAdminAnalyticsOverview($clients),
            'atRiskUsersAnalytics' => $this->advancedAnalyticsService->getAtRiskUsers($clients),
            'riskPatternsAnalytics' => $this->advancedAnalyticsService->getRiskPatterns($clients),
            'supportInsightsAnalytics' => $this->advancedAnalyticsService->getSupportInsights(),
            'kycTrendAnalytics' => $this->advancedAnalyticsService->getKycTrends(),
        ]);
    }

    #[Route('/intelligence-decisionnelle', name: 'analytics_intelligence')]
    public function intelligence(): Response
    {
        $clients = $this->userRepository->findBy(['role' => 'CLIENT'], ['createdAt' => 'DESC'], 120);

        return $this->render('admin/analytics/intelligence.html.twig', [
            'advancedOverview' => $this->advancedAnalyticsService->getAdminAnalyticsOverview($clients),
            'atRiskUsersAnalytics' => $this->advancedAnalyticsService->getAtRiskUsers($clients, 12),
            'riskPatternsAnalytics' => $this->advancedAnalyticsService->getRiskPatterns($clients),
            'supportInsightsAnalytics' => $this->advancedAnalyticsService->getSupportInsights(),
            'kycTrendAnalytics' => $this->advancedAnalyticsService->getKycTrends(),
        ]);
    }
}
