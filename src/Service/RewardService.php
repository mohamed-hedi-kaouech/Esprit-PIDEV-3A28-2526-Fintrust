<?php

namespace App\Service;

use App\Entity\Categorie\Categorie;
use App\Entity\Categorie\Item;
use App\Entity\User\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing rewards based on actual budget-category compliance.
 */
class RewardService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private SmsService $smsService,
        private LoggerInterface $logger,
    ) {}

    /**
     * A reward is granted only when:
     * - at least one category exists
     * - no category exceeds its planned budget
     * - no category reaches or exceeds its alert threshold
     * - no active alert exists on the tracked categories
     */
    public function isEligibleForReward(User $user): bool
    {
        return $this->buildRewardSnapshot($user)['isEligible'];
    }

    public function getCategorySpentAmount(Categorie $category): float
    {
        return (float) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(i.montant), 0)')
            ->from(Item::class, 'i')
            ->where('i.categorie = :category')
            ->setParameter('category', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function generatePromoCode(): string
    {
        return 'FINTRUST-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    public function grantReward(User $user): bool
    {
        if (!$this->isEligibleForReward($user)) {
            $this->logger->info('Reward SMS skipped because reward is still locked.', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
            ]);

            return false;
        }

        $phone = $user->getNumTel();

        if (!$phone) {
            $this->logger->warning('No phone number for user ' . $user->getEmail());

            return false;
        }

        $code = $this->generatePromoCode();

        $message = "Felicitations {$user->getFullName()} !\n"
            . "Vous avez respecte votre budget et vos seuils d alerte.\n"
            . "Voici votre code promo de 10% : {$code}\n"
            . "- L equipe FinTrust";

        return $this->smsService->send($phone, $message);
    }

    /**
     * @return array{
     *     isEligible: bool,
     *     status: string,
     *     statusLabel: string,
     *     lockReason: string,
     *     smsReady: bool,
     *     categoriesChecked: int,
     *     blockedCount: int,
     *     safeCount: int,
     *     completionRate: float,
     *     blockedCategories: list<string>,
     *     alertCategories: list<string>,
     *     thresholdCategories: list<string>,
     *     budgetOverflowCategories: list<string>,
     *     safeCategories: list<string>
     * }
     */
    public function buildRewardSnapshot(?User $user = null): array
    {
        $categories = $this->getTrackedCategories($user);

        if ($categories === []) {
            return [
                'isEligible' => false,
                'status' => 'pending',
                'statusLabel' => 'Non encore debloquee',
                'lockReason' => 'Ajoutez au moins une categorie budgetaire pour activer votre recompense.',
                'smsReady' => false,
                'categoriesChecked' => 0,
                'blockedCount' => 0,
                'safeCount' => 0,
                'completionRate' => 0.0,
                'blockedCategories' => [],
                'alertCategories' => [],
                'thresholdCategories' => [],
                'budgetOverflowCategories' => [],
                'safeCategories' => [],
            ];
        }

        $blockedCategories = [];
        $alertCategories = [];
        $thresholdCategories = [];
        $budgetOverflowCategories = [];
        $safeCategories = [];

        foreach ($categories as $category) {
            $categoryExpenses = $this->getCategorySpentAmount($category);
            $activeAlerts = $this->em->getRepository(\App\Entity\Categorie\Alerte::class)
                ->count(['idCategorie' => $category->getIdCategorie(), 'active' => true]);
            $isBlocked = false;

            if ($categoryExpenses > $category->getBudgetPrevu()) {
                $budgetOverflowCategories[] = $category->getNomCategorie();
                $isBlocked = true;
            }

            if ($categoryExpenses >= $category->getSeuilAlerte()) {
                $thresholdCategories[] = $category->getNomCategorie();
                $isBlocked = true;
            }

            if ($activeAlerts > 0) {
                $alertCategories[] = $category->getNomCategorie();
                $isBlocked = true;
            }

            if ($isBlocked) {
                $blockedCategories[] = $category->getNomCategorie();
            } else {
                $safeCategories[] = $category->getNomCategorie();
            }
        }

        $categoriesChecked = count($categories);
        $safeCount = count($safeCategories);
        $blockedCount = count(array_unique($blockedCategories));

        return [
            'isEligible' => $categoriesChecked > 0 && $blockedCount === 0,
            'status' => $blockedCount === 0 ? 'unlocked' : 'pending',
            'statusLabel' => $blockedCount === 0 ? 'Recompense debloquee' : 'Recompense non encore debloquee',
            'lockReason' => $blockedCount === 0
                ? 'Toutes vos categories sont conformes et aucune alerte active ne bloque votre recompense.'
                : 'Une ou plusieurs categories sont alertees ou proches du seuil. La recompense reste verrouillee.',
            'smsReady' => $blockedCount === 0 && $user instanceof User && (string) $user->getNumTel() !== '',
            'categoriesChecked' => $categoriesChecked,
            'blockedCount' => $blockedCount,
            'safeCount' => $safeCount,
            'completionRate' => $categoriesChecked > 0 ? ($safeCount / $categoriesChecked) * 100 : 0.0,
            'blockedCategories' => array_values(array_unique($blockedCategories)),
            'alertCategories' => array_values(array_unique($alertCategories)),
            'thresholdCategories' => array_values(array_unique($thresholdCategories)),
            'budgetOverflowCategories' => array_values(array_unique($budgetOverflowCategories)),
            'safeCategories' => array_values(array_unique($safeCategories)),
        ];
    }

    /**
     * @return User[]
     */
    public function getEligibleUsers(): array
    {
        $users = $this->userRepository->findBy([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIF,
        ]);

        return array_values(array_filter($users, fn (User $user): bool => $this->isEligibleForReward($user)));
    }

    /**
     * @return Categorie[]
     */
    private function getTrackedCategories(?User $user = null): array
    {
        $repository = $this->em->getRepository(Categorie::class);

        if (!$user instanceof User) {
            return $repository->findAll();
        }

        $ownedCategories = $repository->findBy(['user' => $user], ['idCategorie' => 'ASC']);
        $legacyCategories = $repository->findBy(['user' => null], ['idCategorie' => 'ASC']);

        $categoriesById = [];

        foreach (array_merge($ownedCategories, $legacyCategories) as $category) {
            $categoriesById[$category->getIdCategorie()] = $category;
        }

        return array_values($categoriesById);
    }
}
