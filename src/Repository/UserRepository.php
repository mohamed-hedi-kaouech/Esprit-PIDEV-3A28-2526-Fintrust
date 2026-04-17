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
 * Repository utilisateurs compatible avec la structure SQL actuelle.
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /**
     * @param array{search?: string, role?: string, status?: string, kycStatus?: string, sort?: string, dir?: string} $filters
     */
    public function createFilteredQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u');

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $qb->andWhere('u.nom LIKE :s OR u.prenom LIKE :s OR u.email LIKE :s OR u.numTel LIKE :s')
                ->setParameter('s', $search);
        }

        if (!empty($filters['role'])) {
            $qb->andWhere('u.role = :role')->setParameter('role', $filters['role']);
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('u.status = :status')->setParameter('status', $filters['status']);
        }

        if (isset($filters['kycStatus']) && $filters['kycStatus'] !== '') {
            if ($filters['kycStatus'] === 'NULL') {
                $qb->andWhere('u.kycStatus IS NULL');
            } else {
                $qb->andWhere('u.kycStatus = :kycStatus')
                    ->setParameter('kycStatus', $filters['kycStatus']);
            }
        }

        $allowedSort = ['nom', 'prenom', 'email', 'createdAt', 'status', 'kycStatus', 'role', 'alphabet'];
        $sort = in_array($filters['sort'] ?? '', $allowedSort, true) ? $filters['sort'] : 'createdAt';
        $dir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if ($sort === 'alphabet') {
            $qb->orderBy('u.nom', $dir)
                ->addOrderBy('u.prenom', $dir);
        } else {
            $qb->orderBy('u.' . $sort, $dir);
        }

        return $qb;
    }

    /**
     * @return array{total:int,clients:int,admins:int,actifs:int,attente:int,suspendus:int,inactifs:int,kycOk:int,kycPend:int,kycRefuse:int,vip:int,atRisk:int}
     */
    public function getStats(): array
    {
        $total = $this->count([]);
        $clients = $this->count(['role' => User::ROLE_CLIENT]);
        $admins = $this->count(['role' => User::ROLE_ADMIN]);
        $actifs = $this->count(['status' => User::STATUS_ACTIF]);
        $attente = $this->count(['status' => User::STATUS_EN_ATTENTE]);
        $suspendus = $this->count(['status' => User::STATUS_SUSPENDU]);
        $inactifs = $attente + $suspendus;
        $kycOk = $this->count(['kycStatus' => User::KYC_APPROUVE]);
        $kycPend = $this->count(['kycStatus' => User::KYC_EN_ATTENTE]);
        $kycRefuse = $this->count(['kycStatus' => User::KYC_REFUSE]);

        return [
            'total' => $total,
            'clients' => $clients,
            'admins' => $admins,
            'actifs' => $actifs,
            'attente' => $attente,
            'suspendus' => $suspendus,
            'inactifs' => $inactifs,
            'kycOk' => $kycOk,
            'kycPend' => $kycPend,
            'kycRefuse' => $kycRefuse,
            'vip' => 0,
            'atRisk' => $suspendus,
        ];
    }

    /**
     * @return array{LOW:int,MEDIUM:int,HIGH:int,CRITICAL:int}
     */
    public function getRiskBreakdown(): array
    {
        $clientCount = $this->count(['role' => User::ROLE_CLIENT]);
        $atRisk = $this->count([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_SUSPENDU,
        ]);

        return [
            'LOW' => max(0, $clientCount - $atRisk),
            'MEDIUM' => 0,
            'HIGH' => $atRisk,
            'CRITICAL' => 0,
        ];
    }

    /**
     * @return array<array{month:string,cnt:int}>
     */
    public function getMonthlyRegistrations(): array
    {
        $since = new \DateTimeImmutable('first day of this month -11 months');

        /** @var User[] $users */
        $users = $this->createQueryBuilder('u')
            ->where('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];
        $cursor = $since;
        for ($i = 0; $i < 12; $i++) {
            $counts[$cursor->format('Y-m')] = 0;
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
            $monthly[] = ['month' => $month, 'cnt' => $count];
        }

        return $monthly;
    }

    /**
     * @return array<array{week:string,cnt:int}>
     */
    public function getWeeklyRegistrations(int $weeks = 8): array
    {
        $weeks = max(1, $weeks);
        $start = (new \DateTimeImmutable('monday this week'))->modify('-' . ($weeks - 1) . ' weeks');

        /** @var User[] $users */
        $users = $this->createQueryBuilder('u')
            ->where('u.createdAt >= :start')
            ->setParameter('start', $start)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];
        $cursor = $start;
        for ($i = 0; $i < $weeks; $i++) {
            $counts[$cursor->format('o-\WW')] = 0;
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
            $weekly[] = ['week' => $week, 'cnt' => $count];
        }

        return $weekly;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * @return User[]
     */
    public function findActiveClients(): array
    {
        return $this->findBy([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIF,
        ]);
    }

    /**
     * @return array<int, array{user:User,riskScore:float,alertCount:int,status:string,riskLevel:string}>
     */
    public function getTopRiskUsers(int $limit = 5): array
    {
        $limit = max(1, $limit);

        /** @var User[] $users */
        $users = $this->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', User::ROLE_CLIENT)
            ->orderBy('u.status', 'DESC')
            ->addOrderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($users as $user) {
            $alertCount = 0;
            if (!$user->isKycApproved()) {
                $alertCount++;
            }
            if ($user->getStatus() !== User::STATUS_ACTIF) {
                $alertCount++;
            }

            $rows[] = [
                'user' => $user,
                'riskScore' => $user->getRiskScore(),
                'alertCount' => $alertCount,
                'status' => $user->getStatus(),
                'riskLevel' => $user->getRiskLevel(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['riskScore'] <=> $a['riskScore']);

        return $rows;
    }

    /**
     * @return array{avgFraudScore:float,avgDrift:float,criticalAlerts:int,globalStatus:string,globalTone:string,headline:string}
     */
    public function getSystemHealth(): array
    {
        $pendingKycUsers = $this->count([
            'role' => User::ROLE_CLIENT,
            'kycStatus' => User::KYC_EN_ATTENTE,
        ]);
        $suspendedUsers = $this->count([
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_SUSPENDU,
        ]);

        $avgFraudScore = $suspendedUsers > 0 ? 35.0 : 5.0;
        $avgDrift = $pendingKycUsers > 0 ? 10.0 : 0.0;
        $criticalAlerts = $pendingKycUsers + $suspendedUsers;

        if ($criticalAlerts >= 6) {
            return [
                'avgFraudScore' => $avgFraudScore,
                'avgDrift' => $avgDrift,
                'criticalAlerts' => $criticalAlerts,
                'globalStatus' => 'Risque eleve',
                'globalTone' => 'danger',
                'headline' => 'Systeme sous tension',
            ];
        }

        if ($criticalAlerts >= 3) {
            return [
                'avgFraudScore' => $avgFraudScore,
                'avgDrift' => $avgDrift,
                'criticalAlerts' => $criticalAlerts,
                'globalStatus' => 'Attention',
                'globalTone' => 'warning',
                'headline' => 'Surveillance admin requise',
            ];
        }

        return [
            'avgFraudScore' => $avgFraudScore,
            'avgDrift' => $avgDrift,
            'criticalAlerts' => $criticalAlerts,
            'globalStatus' => 'Systeme stable',
            'globalTone' => 'success',
            'headline' => 'Systeme stable',
        ];
    }
}
