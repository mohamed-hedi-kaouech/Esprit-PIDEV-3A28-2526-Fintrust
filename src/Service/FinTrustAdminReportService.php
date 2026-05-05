<?php

namespace App\Service;

use App\Entity\Categorie\Alerte;
use App\Entity\User\Client\Kyc;
use App\Entity\User\User;
use App\Repository\KycRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nucleos\DompdfBundle\Wrapper\DompdfWrapperInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class FinTrustAdminReportService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly KycRepository $kycRepository,
        private readonly AdvancedAnalyticsService $advancedAnalyticsService,
        private readonly AccountDeactivationRequestService $accountDeactivationRequestService,
        private readonly EntityManagerInterface $entityManager,
        private readonly DompdfWrapperInterface $pdfWrapper,
        private readonly Environment $twig,
        private readonly string $projectDir,
    ) {
    }

    public function exportGlobalReportPdf(): Response
    {
        $clients = $this->userRepository->findBy(['role' => User::ROLE_CLIENT], ['createdAt' => 'DESC'], 160);
        $pendingDeactivationRequests = $this->accountDeactivationRequestService->getPendingForUsers($clients);
        $pendingDeactivationMap = [];
        foreach ($pendingDeactivationRequests as $request) {
            $pendingDeactivationMap[(int) $request['userId']] = $request;
        }

        $stats = $this->userRepository->getStats();
        $kycOverview = $this->kycRepository->getAdminOverview();
        $advancedOverview = $this->advancedAnalyticsService->getAdminAnalyticsOverview($clients);
        $atRiskUsers = $this->advancedAnalyticsService->getAtRiskUsers($clients, 6);
        $riskPatterns = $this->advancedAnalyticsService->getRiskPatterns($clients);
        $deactivationInsights = $this->advancedAnalyticsService->getAccountDeactivationInsights($clients, $pendingDeactivationMap);
        $supportInsights = $this->advancedAnalyticsService->getSupportInsights();

        $alerts = $this->entityManager->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.categorie', 'c')
            ->addSelect('c')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getResult();

        $securitySnapshots = $this->readSecuritySnapshots();
        $latestUsers = array_slice($clients, 0, 5);
        $blockedUsers = array_values(array_filter(
            $clients,
            static fn (User $user): bool => $user->getStatus() === User::STATUS_SUSPENDU
        ));
        $nonVerifiedUsers = array_values(array_filter(
            $clients,
            static fn (User $user): bool => !$user->isVerified() || !$user->isKycApproved()
        ));
        $incompleteKycFiles = $this->kycRepository->findPending();

        $report = [
            'generatedAt' => new \DateTimeImmutable(),
            'summary' => [
                'totalUsers' => $stats['total'] ?? 0,
                'activeUsers' => $stats['actifs'] ?? 0,
                'inactiveUsers' => $stats['inactifs'] ?? 0,
                'kycApproved' => $stats['kycOk'] ?? 0,
                'kycPending' => $stats['kycPend'] ?? 0,
                'kycRejected' => $stats['kycRefuse'] ?? 0,
                'importantAlerts' => count(array_filter($alerts, static fn (Alerte $alerte): bool => $alerte->getActive() === true)),
            ],
            'analysis' => [
                'atRiskUsers' => $atRiskUsers,
                'latestUsers' => $latestUsers,
                'blockedUsers' => array_slice($blockedUsers, 0, 5),
                'nonVerifiedUsers' => array_slice($nonVerifiedUsers, 0, 5),
            ],
            'security' => [
                'suspiciousLogins' => $securitySnapshots['logins'],
                'locationChanges' => $securitySnapshots['locations'],
                'deactivatedAccounts' => array_slice($deactivationInsights['hypotheses'] ?? [], 0, 5),
                'incompleteKyc' => array_slice($incompleteKycFiles, 0, 5),
            ],
            'recommendations' => $this->buildRecommendations(
                $advancedOverview,
                $stats,
                $atRiskUsers,
                $incompleteKycFiles,
                $blockedUsers,
                $nonVerifiedUsers
            ),
            'supportInsights' => $supportInsights,
            'riskPatterns' => $riskPatterns,
            'alerts' => $alerts,
            'pendingDeactivationRequests' => $pendingDeactivationRequests,
            'kycOverview' => $kycOverview,
        ];

        $html = $this->twig->render('admin/reports/admin_report_pdf.html.twig', [
            'report' => $report,
        ]);

        $filename = 'fintrust_admin_report_' . date('Ymd_His') . '.pdf';

        return new Response(
            $this->pdfWrapper->getPdf($html, ['isRemoteEnabled' => true]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * @return array{logins:array<int,array<string,mixed>>,locations:array<int,array<string,mixed>>}
     */
    private function readSecuritySnapshots(): array
    {
        return [
            'logins' => $this->readSecurityDirectory($this->projectDir . '/var/security-login', 'checkedAt'),
            'locations' => $this->readSecurityDirectory($this->projectDir . '/var/security-browser-location', 'checkedAt'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readSecurityDirectory(string $directory, string $dateField): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $items = [];
        foreach (glob($directory . '/user_*.json') ?: [] as $path) {
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $payload = json_decode($content, true);
            if (!is_array($payload)) {
                continue;
            }

            $userId = (int) preg_replace('/[^0-9]/', '', basename($path));
            $user = $userId > 0 ? $this->userRepository->find($userId) : null;

            $payload['userId'] = $userId;
            $payload['userName'] = $user instanceof User ? $user->getFullName() : 'Utilisateur #' . $userId;
            $items[] = $payload;
        }

        usort($items, static function (array $left, array $right) use ($dateField): int {
            return strcmp((string) ($right[$dateField] ?? ''), (string) ($left[$dateField] ?? ''));
        });

        return array_slice($items, 0, 5);
    }

    /**
     * @param array<string,mixed> $advancedOverview
     * @param array<string,mixed> $stats
     * @param array<int,array<string,mixed>> $atRiskUsers
     * @param Kyc[] $incompleteKycFiles
     * @param User[] $blockedUsers
     * @param User[] $nonVerifiedUsers
     * @return array<int,array{title:string,detail:string,tone:string}>
     */
    private function buildRecommendations(
        array $advancedOverview,
        array $stats,
        array $atRiskUsers,
        array $incompleteKycFiles,
        array $blockedUsers,
        array $nonVerifiedUsers,
    ): array {
        $recommendations = [
            [
                'title' => 'Relancer les KYC en attente',
                'detail' => sprintf('%d dossier(s) attendent encore une revue ou une relance admin.', count($incompleteKycFiles)),
                'tone' => 'warning',
            ],
            [
                'title' => 'Verifier les utilisateurs a haut risque',
                'detail' => sprintf('%d profil(s) affichent actuellement les scores de desengagement les plus eleves.', count($atRiskUsers)),
                'tone' => 'danger',
            ],
            [
                'title' => 'Contacter les comptes inactifs',
                'detail' => sprintf('%d compte(s) sont inactifs ou suspendus et meritent une action ciblee.', (int) ($stats['inactifs'] ?? 0)),
                'tone' => 'info',
            ],
            [
                'title' => 'Renforcer la securite des comptes sensibles',
                'detail' => sprintf('%d compte(s) non verifies ou sensibles doivent etre recontroles.', count($nonVerifiedUsers)),
                'tone' => 'primary',
            ],
        ];

        foreach (array_slice($advancedOverview['prioritySignals'] ?? [], 0, 2) as $signal) {
            $recommendations[] = [
                'title' => 'Signal analytique prioritaire',
                'detail' => (string) $signal,
                'tone' => 'secondary',
            ];
        }

        if ($blockedUsers !== []) {
            $recommendations[] = [
                'title' => 'Revoir les comptes bloques',
                'detail' => sprintf('%d compte(s) suspendus peuvent necessiter un arbitrage ou un recontact.', count($blockedUsers)),
                'tone' => 'danger',
            ];
        }

        return $recommendations;
    }
}
