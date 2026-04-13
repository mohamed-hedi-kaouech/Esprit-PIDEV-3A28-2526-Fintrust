<?php

namespace App\Repository;

use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Repository — Utilisateurs.
 *
 * Méthodes personnalisées : recherche, filtres, tri, statistiques, inscriptions mensuelles.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Mise à jour automatique du hash de mot de passe (rehash Symfony).
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    // =========================================================================
    // RECHERCHE / FILTRES / TRI
    // =========================================================================

    /**
     * Retourne un QueryBuilder filtrable pour la liste admin.
     *
     * Paramètres supportés :
     * - search    : recherche sur nom, prénom, email, téléphone
     * - role      : CLIENT | ADMIN
     * - status    : EN_ATTENTE | ACTIF | SUSPENDU
     * - kycStatus : NULL | EN_ATTENTE | APPROUVE | REFUSE
     * - sort      : colonne de tri (whitelist)
     * - dir       : ASC | DESC
     *
     * @param array{search?: string, role?: string, status?: string, kycStatus?: string, sort?: string, dir?: string} $filters
     */
    public function createFilteredQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');

        // Recherche textuelle (nom, prénom, email, téléphone)
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $qb->andWhere('u.nom LIKE :s OR u.prenom LIKE :s OR u.email LIKE :s OR u.numTel LIKE :s')
               ->setParameter('s', $search);
        }

        // Filtre par rôle
        if (!empty($filters['role'])) {
            $qb->andWhere('u.role = :role')->setParameter('role', $filters['role']);
        }

        // Filtre par statut du compte
        if (!empty($filters['status'])) {
            $qb->andWhere('u.status = :status')->setParameter('status', $filters['status']);
        }

        // Filtre par statut KYC (NULL = non soumis)
        if (isset($filters['kycStatus']) && $filters['kycStatus'] !== '') {
            if ($filters['kycStatus'] === 'NULL') {
                $qb->andWhere('u.kycStatus IS NULL');
            } else {
                $qb->andWhere('u.kycStatus = :kycStatus')
                   ->setParameter('kycStatus', $filters['kycStatus']);
            }
        }

        // Tri sécurisé (whitelist des colonnes autorisées)
        $allowedSort = ['nom', 'prenom', 'email', 'createdAt', 'status', 'kycStatus', 'role', 'alphabet'];
        $sort = in_array($filters['sort'] ?? '', $allowedSort, true) ? $filters['sort'] : 'createdAt';
        $dir  = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if ($sort === 'alphabet') {
            $qb->orderBy('u.nom', $dir)
                ->addOrderBy('u.prenom', $dir);
        } else {
            $qb->orderBy('u.' . $sort, $dir);
        }

        return $qb;
    }

    // =========================================================================
    // STATISTIQUES
    // =========================================================================

    /**
     * Retourne les statistiques globales pour le dashboard admin.
     *
     * @return array{total: int, clients: int, admins: int, actifs: int, attente: int, suspendus: int, inactifs: int, kycOk: int, kycPend: int, kycRefuse: int}
     */
    public function getStats(): array
    {
        $total      = $this->count([]);
        $clients    = $this->count(['role' => User::ROLE_CLIENT]);
        $admins     = $this->count(['role' => User::ROLE_ADMIN]);
        $actifs     = $this->count(['status' => User::STATUS_ACTIF]);
        $attente    = $this->count(['status' => User::STATUS_EN_ATTENTE]);
        $suspendus  = $this->count(['status' => User::STATUS_SUSPENDU]);
        $inactifs   = $attente + $suspendus;
        $kycOk      = $this->count(['kycStatus' => User::KYC_APPROUVE]);
        $kycPend    = $this->count(['kycStatus' => User::KYC_EN_ATTENTE]);
        $kycRefuse  = $this->count(['kycStatus' => User::KYC_REFUSE]);

        $vip = $this->count(['clientSegment' => User::SEGMENT_VIP]);
        $atRisk = $this->count(['clientSegment' => User::SEGMENT_AT_RISK]);

        return compact('total', 'clients', 'admins', 'actifs', 'attente', 'suspendus', 'inactifs', 'kycOk', 'kycPend', 'kycRefuse', 'vip', 'atRisk');
    }

    /**
     * @return array{LOW:int, MEDIUM:int, HIGH:int, CRITICAL:int}
     */
    public function getRiskBreakdown(): array
    {
        $data = [
            User::RISK_LOW => 0,
            User::RISK_MEDIUM => 0,
            User::RISK_HIGH => 0,
            User::RISK_CRITICAL => 0,
        ];

        $rows = $this->createQueryBuilder('u')
            ->select('u.riskLevel AS level, COUNT(u.id) AS cnt')
            ->where('u.role = :role')
            ->setParameter('role', User::ROLE_CLIENT)
            ->groupBy('u.riskLevel')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $level = $row['level'] ?? null;
            if ($level !== null && array_key_exists($level, $data)) {
                $data[$level] = (int) $row['cnt'];
            }
        }

        return [
            'LOW' => $data[User::RISK_LOW],
            'MEDIUM' => $data[User::RISK_MEDIUM],
            'HIGH' => $data[User::RISK_HIGH],
            'CRITICAL' => $data[User::RISK_CRITICAL],
        ];
    }

    /**
     * Retourne le nombre d'inscriptions par mois sur les 12 derniers mois.
     * Utilisé pour le graphique du dashboard admin.
     *
     * @return array<array{month: string, cnt: int}>
     */
    public function getMonthlyRegistrations(): array
    {
        $since = new \DateTimeImmutable('first day of this month -11 months');

        /** @var User[] $users */
        $users = $this->createQueryBuilder('u')
            ->select('u')
            ->where('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];
        $cursor = $since;

        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $counts[$key] = 0;
            $cursor = $cursor->modify('+1 month');
        }

        foreach ($users as $user) {
            $key = $user->getCreatedAt()->format('Y-m');
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        $monthly = [];
        foreach ($counts as $month => $count) {
            $monthly[] = [
                'month' => $month,
                'cnt' => $count,
            ];
        }

        return $monthly;
    }

    /**
     * Retourne les inscriptions par semaine sur une fenêtre glissante.
     *
     * @return array<array{week: string, cnt: int}>
     */
    public function getWeeklyRegistrations(int $weeks = 8): array
    {
        $weeks = max(1, $weeks);
        $start = (new \DateTimeImmutable('monday this week'))->modify('-' . ($weeks - 1) . ' weeks');

        /** @var User[] $users */
        $users = $this->createQueryBuilder('u')
            ->select('u')
            ->where('u.createdAt >= :start')
            ->setParameter('start', $start)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];
        $cursor = $start;

        for ($i = 0; $i < $weeks; $i++) {
            $key = $cursor->format('o-\WW');
            $counts[$key] = 0;
            $cursor = $cursor->modify('+1 week');
        }

        foreach ($users as $user) {
            $key = $user->getCreatedAt()->format('o-\WW');
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        $weekly = [];
        foreach ($counts as $week => $count) {
            $weekly[] = [
                'week' => $week,
                'cnt' => $count,
            ];
        }

        return $weekly;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /** @return User[] */
    public function findActiveClients(): array
    {
        return $this->findBy([
            'role'   => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIF,
        ]);
    }

    /**
     * Retourne les clients les plus sensibles selon leur score de risque.
     *
     * @return array<int, array{
     *   user: User,
     *   riskScore: float,
     *   alertCount: int,
     *   status: string,
     *   riskLevel: string
     * }>
     */
    public function getTopRiskUsers(int $limit = 5): array
    {
        $limit = max(1, $limit);

        /** @var User[] $users */
        $users = $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', User::ROLE_CLIENT)
            ->orderBy('u.riskScore', 'DESC')
            ->addOrderBy('u.fraudScore', 'DESC')
            ->addOrderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $topRisk = [];

        foreach ($users as $user) {
            $alertCount = 0;
            if ($user->getRiskLevel() === User::RISK_CRITICAL) {
                $alertCount += 3;
            } elseif ($user->getRiskLevel() === User::RISK_HIGH) {
                $alertCount += 2;
            } elseif ($user->getRiskLevel() === User::RISK_MEDIUM) {
                $alertCount += 1;
            }
            if (!$user->isKycApproved()) {
                $alertCount++;
            }
            if ($user->getStatus() !== User::STATUS_ACTIF) {
                $alertCount++;
            }
            if ($user->getFraudScore() >= 55) {
                $alertCount++;
            }

            $topRisk[] = [
                'user' => $user,
                'riskScore' => $user->getRiskScore(),
                'alertCount' => $alertCount,
                'status' => $user->getStatus(),
                'riskLevel' => $user->getRiskLevel(),
            ];
        }

        return $topRisk;
    }

    /**
     * Retourne un score global de santé système basé sur le moteur de risque.
     *
     * @return array{
     *   avgFraudScore: float,
     *   avgDrift: float,
     *   criticalAlerts: int,
     *   globalStatus: string,
     *   globalTone: string,
     *   headline: string
     * }
     */
    public function getSystemHealth(): array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('AVG(u.fraudScore) AS avgFraud', 'AVG(u.riskScore) AS avgRisk')
            ->where('u.role = :role')
            ->setParameter('role', User::ROLE_CLIENT);

        $averages = $qb->getQuery()->getSingleResult();
        $avgFraudScore = round((float) ($averages['avgFraud'] ?? 0), 1);
        $avgRiskScore = round((float) ($averages['avgRisk'] ?? 0), 1);
        $avgDrift = round(abs($avgRiskScore - $avgFraudScore), 1);

        $criticalUsers = $this->count([
            'role' => User::ROLE_CLIENT,
            'riskLevel' => User::RISK_CRITICAL,
        ]);
        $highRiskUsers = $this->count([
            'role' => User::ROLE_CLIENT,
            'riskLevel' => User::RISK_HIGH,
        ]);
        $pendingKycUsers = $this->count([
            'role' => User::ROLE_CLIENT,
            'kycStatus' => User::KYC_EN_ATTENTE,
        ]);
        $suspendedUsers = $this->count([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_SUSPENDU,
        ]);

        $criticalAlerts = $criticalUsers + max(0, (int) round($highRiskUsers / 2)) + $pendingKycUsers + $suspendedUsers;

        if ($criticalAlerts >= 6 || $avgFraudScore >= 55 || $avgDrift >= 28) {
            return [
                'avgFraudScore' => $avgFraudScore,
                'avgDrift' => $avgDrift,
                'criticalAlerts' => $criticalAlerts,
                'globalStatus' => 'Risque eleve',
                'globalTone' => 'danger',
                'headline' => 'Systeme sous tension',
            ];
        }

        if ($criticalAlerts >= 3 || $avgFraudScore >= 35 || $avgDrift >= 15) {
            return [
                'avgFraudScore' => $avgFraudScore,
                'avgDrift' => $avgDrift,
                'criticalAlerts' => $criticalAlerts,
                'globalStatus' => 'Attention drift',
                'globalTone' => 'warning',
                'headline' => 'Attention drift',
            ];
        }

        return [
            'avgFraudScore' => $avgFraudScore,
            'avgDrift' => $avgDrift,
            'criticalAlerts' => $criticalAlerts,
            'globalStatus' => 'System stable',
            'globalTone' => 'success',
            'headline' => 'Systeme stable',
        ];
    }
}
