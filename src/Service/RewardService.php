<?php

namespace App\Service;

use App\Entity\Categorie\Alerte;
use App\Entity\Categorie\Categorie;
use App\Entity\Categorie\Item;
use App\Entity\User\User;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing user rewards based on budget compliance.
 */
class RewardService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private CategorieRepository $categorieRepository,
        private ItemRepository $itemRepository,
        private SmsService $smsService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Check if a user is eligible for a reward.
     * A user is eligible if:
     * - budget_total is set
     * - at least one category exists
     * - total expenses do not exceed budget_total
     * - no category currently exceeds its alert threshold (seuil)
     */
    public function isEligibleForReward(User $user): bool
    {
        if (!$user->getBudgetTotal()) {
            return false;
        }

        $totalBudget = (float) $user->getBudgetTotal();
        $totalExpenses = $this->getTotalExpensesForUser($user);

        // Budget total dépassé
        if ($totalExpenses > $totalBudget) {
            return false;
        }

        // Doit avoir au moins une catégorie
        $categories = $this->em->getRepository(\App\Entity\Categorie\Categorie::class)->findAll();
        if (empty($categories)) {
            return false;
        }

        // Vérifier si les dépenses actuelles dépassent le seuil d'alerte de chaque catégorie
        foreach ($categories as $category) {
            $categoryExpenses = $this->getExpensesForCategory($category);

            // Dépenses dépassent le budget prévu
            if ($categoryExpenses > $category->getBudgetPrevu()) {
                return false;
            }

            // Dépenses dépassent le seuil d'alerte
            if ($category->getSeuilAlerte() !== null && $categoryExpenses >= $category->getSeuilAlerte()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get total expenses for a user.
     */
    private function getTotalExpensesForUser(User $user): float
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('SUM(i.montant)')
            ->from(Item::class, 'i')
            ->join('i.categorie', 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user);

        return (float) $qb->getQuery()->getSingleScalarResult() ?: 0.0;
    }

    /**
     * Get expenses for a category.
     */
    private function getExpensesForCategory(Categorie $category): float
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('SUM(i.montant)')
            ->from(Item::class, 'i')
            ->where('i.categorie = :category')
            ->setParameter('category', $category);

        return (float) $qb->getQuery()->getSingleScalarResult() ?: 0.0;
    }

    /**
     * Generate a unique promo code locally.
     */
    private function generatePromoCode(): string
    {
        return 'FINTRUST-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    /**
     * Grant reward to user via SMS.
     */
    public function grantReward(User $user): bool
    {
        $phone = $user->getNumTel();

        if (!$phone) {
            $this->logger->warning('No phone number for user ' . $user->getEmail());
            return false;
        }

        $code = $this->generatePromoCode();

        $message = "Félicitations {$user->getFullName()} ! 🎉\n"
            . "Vous avez respecté votre budget ce mois-ci.\n"
            . "Voici votre code promo de 10% : {$code}\n"
            . "- L'équipe FinTrust";

        return $this->smsService->send($phone, $message);
    }

    /**
     * Get eligible users.
     */
    public function getEligibleUsers(): array
    {
        $users = $this->userRepository->findBy([
            'role'   => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIF,
        ]);

        return array_filter($users, fn(User $user) => $this->isEligibleForReward($user));
    }
}
