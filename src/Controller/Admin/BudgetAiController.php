<?php
namespace App\Controller\Admin;
use App\Entity\User\User;
use App\Repository\UserRepository;
use App\Service\BudgetAiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/admin/budget/ai', name: 'admin_budget_ai_')]
class BudgetAiController extends AbstractController
{
    public function __construct(
        private BudgetAiService $aiService,
        private UserRepository $userRepository,
    ) {}

    #[Route('', name: 'chat', methods: ['GET'])]
    public function chat(): Response
    {
        /** @var User[] $clients */
        $clients = $this->userRepository->findBy(['role' => User::ROLE_CLIENT], ['createdAt' => 'DESC'], 240);

        $totalClients = count($clients);
        $activeSavers = 0;
        $targetedCoaching = 0;
        $disciplinedProfiles = 0;
        $underWatch = 0;
        $estimatedSavingsPool = 0.0;
        $monthlyCapacityTotal = 0.0;
        $segments = [
            'regular' => 0,
            'builders' => 0,
            'at_risk' => 0,
            'premium' => 0,
        ];
        $priorityProfiles = [];

        foreach ($clients as $user) {
            $budgetTotal = (float) ($user->getBudgetTotal() ?? '0');
            $transactionFrequency = $user->getTransactionFrequency();
            $avgTransaction = $user->getAverageTransactionAmount();
            $riskScore = $user->getRiskScore();
            $riskLevel = $user->getRiskLevel();
            $fraudScore = $user->getFraudScore();

            $monthlyCapacity = max(80.0, round(($budgetTotal * 0.11) + ($avgTransaction * 0.18) + ($transactionFrequency * 65), 2));
            $estimatedSavings = max(120.0, round(($budgetTotal * 0.26) + ($avgTransaction * 0.4), 2));
            $disciplineScore = (int) max(28, min(96, round(68 + ($transactionFrequency * 18) - ($riskScore * 0.22) - ($fraudScore * 0.10))));
            $watchLevel = in_array($riskLevel, [User::RISK_HIGH, User::RISK_CRITICAL], true) || $fraudScore >= 60;

            if ($monthlyCapacity >= 260) {
                $activeSavers++;
            }
            if ($disciplineScore >= 78) {
                $disciplinedProfiles++;
            }
            if ($watchLevel) {
                $underWatch++;
            }
            if ($disciplineScore < 58 || !$user->isKycApproved() || !$user->isVerified()) {
                $targetedCoaching++;
            }

            if ($user->isVip()) {
                $segments['premium']++;
            } elseif ($watchLevel || $user->isAtRisk()) {
                $segments['at_risk']++;
            } elseif ($monthlyCapacity >= 260) {
                $segments['builders']++;
            } else {
                $segments['regular']++;
            }

            $estimatedSavingsPool += $estimatedSavings;
            $monthlyCapacityTotal += $monthlyCapacity;

            $priorityProfiles[] = [
                'user' => $user,
                'disciplineScore' => $disciplineScore,
                'monthlyCapacity' => $monthlyCapacity,
                'estimatedSavings' => $estimatedSavings,
                'watchLevel' => $watchLevel,
                'segmentLabel' => $user->isVip() ? 'Premium' : ($watchLevel ? 'A surveiller' : ($monthlyCapacity >= 260 ? 'Bon epargnant' : 'Potentiel a activer')),
                'segmentTone' => $user->isVip() ? 'violet' : ($watchLevel ? 'red' : ($monthlyCapacity >= 260 ? 'green' : 'blue')),
                'statusLabel' => $disciplineScore >= 78 ? 'Discipline forte' : ($disciplineScore >= 58 ? 'A stabiliser' : 'A coacher'),
                'statusTone' => $disciplineScore >= 78 ? 'green' : ($disciplineScore >= 58 ? 'amber' : 'red'),
                'lastSync' => $user->getBehaviorUpdatedAt()?->format('d/m/Y') ?? $user->getCreatedAt()->format('d/m/Y'),
            ];
        }

        usort(
            $priorityProfiles,
            static fn (array $left, array $right): int => ($right['disciplineScore'] <=> $left['disciplineScore'])
                ?: ($right['monthlyCapacity'] <=> $left['monthlyCapacity'])
        );

        $priorityProfiles = array_slice($priorityProfiles, 0, 8);
        $averageDiscipline = $totalClients > 0 ? (int) round(array_sum(array_map(static fn (array $row): int => $row['disciplineScore'], $priorityProfiles)) / count($priorityProfiles ?: [1])) : 0;
        $averageMonthlyCapacity = $totalClients > 0 ? round($monthlyCapacityTotal / $totalClients, 2) : 0.0;
        $adoptionRate = $totalClients > 0 ? (int) round(($activeSavers / $totalClients) * 100) : 0;
        $coachingRate = $totalClients > 0 ? (int) round(($targetedCoaching / $totalClients) * 100) : 0;

        $kpis = [
            ['icon' => 'bi-piggy-bank', 'tone' => 'blue', 'label' => 'Clients epargnants actifs', 'value' => $activeSavers, 'meta' => $adoptionRate . '% du parc client'],
            ['icon' => 'bi-wallet2', 'tone' => 'cyan', 'label' => 'Capacite moyenne mensuelle', 'value' => number_format($averageMonthlyCapacity, 0, ',', ' ') . ' DT', 'meta' => 'Potentiel moyen estime par client'],
            ['icon' => 'bi-graph-up-arrow', 'tone' => 'green', 'label' => 'Discipline d epargne', 'value' => $averageDiscipline . '/100', 'meta' => 'Score moyen sur les profils prioritaires'],
            ['icon' => 'bi-person-workspace', 'tone' => 'amber', 'label' => 'Coaching a lancer', 'value' => $targetedCoaching, 'meta' => $coachingRate . '% des clients a accompagner'],
            ['icon' => 'bi-shield-exclamation', 'tone' => 'violet', 'label' => 'Profils sous surveillance', 'value' => $underWatch, 'meta' => 'Risque, fraude ou faible regularite'],
        ];

        $segmentsView = [
            ['label' => 'Reguliers', 'value' => $segments['regular'], 'tone' => 'blue', 'icon' => 'bi-people'],
            ['label' => 'Constructeurs', 'value' => $segments['builders'], 'tone' => 'green', 'icon' => 'bi-rocket-takeoff'],
            ['label' => 'A risque', 'value' => $segments['at_risk'], 'tone' => 'red', 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Premium', 'value' => $segments['premium'], 'tone' => 'violet', 'icon' => 'bi-gem'],
        ];

        $recommendations = [
            ['tone' => 'blue', 'icon' => 'bi-chat-dots', 'title' => 'Relancer les clients a faible cadence', 'text' => 'Cibler les profils avec discipline < 58 pour proposer un accompagnement budgetaire.'],
            ['tone' => 'green', 'icon' => 'bi-patch-check', 'title' => 'Activer les epargnants prometteurs', 'text' => 'Proposer une routine automatique aux clients avec capacite > 260 DT/mois.'],
            ['tone' => 'amber', 'icon' => 'bi-lightning-charge', 'title' => 'Convertir le potentiel inactif', 'text' => 'Identifier les clients au budget solide mais encore peu engages dans l epargne.'],
        ];

        return $this->render('admin/budget_ai/chat.html.twig', [
            'kpis' => $kpis,
            'totalClients' => $totalClients,
            'activeSavers' => $activeSavers,
            'averageMonthlyCapacity' => $averageMonthlyCapacity,
            'averageDiscipline' => $averageDiscipline,
            'targetedCoaching' => $targetedCoaching,
            'underWatch' => $underWatch,
            'estimatedSavingsPool' => $estimatedSavingsPool,
            'adoptionRate' => $adoptionRate,
            'segments' => $segmentsView,
            'priorityProfiles' => $priorityProfiles,
            'recommendations' => $recommendations,
        ]);
    }
    #[Route('/ask', name: 'ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $question = trim($data['question'] ?? '');
        if (empty($question)) {
            return $this->json(['error' => 'Question vide'], 400);
        }
        if (mb_strlen($question) > 500) {
            return $this->json(['error' => 'Question trop longue'], 400);
        }
        $answer = $this->aiService->answer($question);
        return $this->json(['answer' => $answer]);
    }
}
