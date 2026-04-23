<?php

namespace App\Controller\Admin;

use App\Repository\KycRepository;
use App\Repository\UserRepository;
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
        ]);
    }
}
