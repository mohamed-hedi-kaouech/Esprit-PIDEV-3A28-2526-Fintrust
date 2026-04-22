<?php

namespace App\Repository\User;

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

}
