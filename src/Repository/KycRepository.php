<?php

namespace App\Repository;

use App\Entity\User\Client\Kyc;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository — Dossiers KYC.
 *
 * Méthodes personnalisées pour la recherche, le filtrage et les statistiques KYC.
 *
 * @extends ServiceEntityRepository<Kyc>
 */
class KycRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Kyc::class);
    }

    /**
     * Retourne le dernier KYC soumis par un utilisateur (tri par date décroissante).
     */
    public function findLatestByUser(User $user): ?Kyc
    {
        return $this->createQueryBuilder('k')
            ->where('k.user = :user')
            ->setParameter('user', $user)
            ->orderBy('k.dateSubmission', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les dossiers KYC en attente, triés par date de soumission (plus anciens en premier).
     *
     * @return Kyc[]
     */
    public function findPending(): array
    {
        return $this->findBy(
            ['statut' => Kyc::STATUT_EN_ATTENTE],
            ['dateSubmission' => 'ASC']
        );
    }

    /**
     * Retourne un KYC avec ses fichiers chargés en une seule requête (évite le problème N+1).
     */
    public function findWithFiles(int $id): ?Kyc
    {
        return $this->createQueryBuilder('k')
            ->leftJoin('k.files', 'f')
            ->addSelect('f')
            ->where('k.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte les KYC par statut (pour les statistiques admin).
     *
     * @return array{EN_ATTENTE: int, APPROUVE: int, REFUSE: int}
     */
    public function countByStatut(): array
    {
        $results = $this->createQueryBuilder('k')
            ->select('k.statut, COUNT(k.id) AS cnt')
            ->groupBy('k.statut')
            ->getQuery()
            ->getArrayResult();

        $counts = ['EN_ATTENTE' => 0, 'APPROUVE' => 0, 'REFUSE' => 0];
        foreach ($results as $row) {
            $counts[$row['statut']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @return array{
     *   pending:int,
     *   approved:int,
     *   refused:int,
     *   approvedToday:int,
     *   avgProcessingMinutes:int,
     *   latestSubmission:?string
     * }
     */
    public function getAdminOverview(): array
    {
        $counts = $this->countByStatut();
        $todayStart = new \DateTimeImmutable('today');
        $tomorrowStart = $todayStart->modify('+1 day');

        $approvedToday = (int) $this->createQueryBuilder('k')
            ->select('COUNT(k.id)')
            ->where('k.statut = :approved')
            ->andWhere('k.dateSubmission >= :today')
            ->andWhere('k.dateSubmission < :tomorrow')
            ->setParameter('approved', Kyc::STATUT_APPROUVE)
            ->setParameter('today', $todayStart)
            ->setParameter('tomorrow', $tomorrowStart)
            ->getQuery()
            ->getSingleScalarResult();

        /** @var Kyc[] $processed */
        $processed = $this->createQueryBuilder('k')
            ->where('k.statut IN (:statuses)')
            ->setParameter('statuses', [Kyc::STATUT_APPROUVE, Kyc::STATUT_REFUSE])
            ->orderBy('k.dateSubmission', 'DESC')
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

        $avgProcessingMinutes = 0;
        if ($processed !== []) {
            $totalMinutes = 0;
            $now = new \DateTimeImmutable();
            foreach ($processed as $kyc) {
                $interval = $kyc->getDateSubmission()->diff($now);
                $minutes = max(5, ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i);
                $totalMinutes += min($minutes, 240);
            }
            $avgProcessingMinutes = (int) round($totalMinutes / count($processed));
        }

        $latest = $this->createQueryBuilder('k')
            ->orderBy('k.dateSubmission', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'pending' => $counts[Kyc::STATUT_EN_ATTENTE] ?? 0,
            'approved' => $counts[Kyc::STATUT_APPROUVE] ?? 0,
            'refused' => $counts[Kyc::STATUT_REFUSE] ?? 0,
            'approvedToday' => $approvedToday,
            'avgProcessingMinutes' => $avgProcessingMinutes,
            'latestSubmission' => $latest instanceof Kyc ? $latest->getDateSubmission()->format('d/m/Y') : null,
        ];
    }

    /**
     * @return Kyc[]
     */
    public function findRecentActivity(int $limit = 5): array
    {
        return $this->createQueryBuilder('k')
            ->leftJoin('k.user', 'u')
            ->addSelect('u')
            ->orderBy('k.dateSubmission', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
