<?php

namespace App\Service;

use App\Entity\Publication\Publication;
use App\Entity\User\Feedback;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class DynamicClientNotificationService
{
    private const SESSION_KEY = 'front_dynamic_notifications_last_check';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getLastCheck(SessionInterface $session): \DateTimeImmutable
    {
        $stored = $session->get(self::SESSION_KEY);

        if ($stored instanceof \DateTimeImmutable) {
            return $stored;
        }

        if ($stored instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($stored);
        }

        return new \DateTimeImmutable('-1 day');
    }

    public function markChecked(SessionInterface $session): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $session->set(self::SESSION_KEY, $now);

        return $now;
    }

    public function getUnreadCount(User $user, SessionInterface $session): int
    {
        return count($this->getNotifications($user, $this->getLastCheck($session)));
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     source: string,
     *     type: string,
     *     title: string,
     *     message: string,
     *     date: \DateTimeInterface,
     *     read: bool,
     *     actionUrl: string|null,
     *     actionLabel: string|null
     * }>
     */
    public function getNotifications(User $user, \DateTimeInterface $lastVisit): array
    {
        $notifications = [];
        $preferredCategory = $this->findPreferredCategory($user);

        $publications = $this->em->getRepository(Publication::class)
            ->createQueryBuilder('p')
            ->where('p.datePublication IS NOT NULL')
            ->andWhere('p.datePublication > :lastVisit')
            ->andWhere('p.estVisible = :visible')
            ->andWhere('p.statut = :status')
            ->setParameter('lastVisit', $lastVisit)
            ->setParameter('visible', true)
            ->setParameter('status', Publication::STATUS_PUBLIE)
            ->orderBy('p.datePublication', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($publications as $publication) {
            if (!$publication instanceof Publication || !$publication->getDatePublication()) {
                continue;
            }

            $isPreferredCategory = $preferredCategory !== null
                && strtoupper((string) $publication->getCategorie()) === strtoupper($preferredCategory);

            $notifications[] = [
                'id' => 'dynamic-publication-' . $publication->getId(),
                'source' => 'dynamic',
                'type' => $isPreferredCategory ? 'SUCCESS' : 'INFO',
                'title' => $isPreferredCategory ? 'Categorie preferee' : 'Nouvelle publication',
                'message' => $isPreferredCategory
                    ? sprintf(
                        'Nouvelle publication dans votre categorie preferee "%s" : %s',
                        (string) $publication->getCategorie(),
                        (string) $publication->getTitre()
                    )
                    : 'Nouvelle publication : ' . (string) $publication->getTitre(),
                'date' => $publication->getDatePublication(),
                'read' => false,
                'actionUrl' => '/espace-client/publications/' . $publication->getId(),
                'actionLabel' => 'Voir la publication',
            ];
        }

        $feedbacks = $this->em->getRepository(Feedback::class)
            ->createQueryBuilder('f')
            ->join('f.publication', 'p')
            ->where('f.user = :user')
            ->andWhere('f.adminResponse IS NOT NULL')
            ->andWhere("TRIM(f.adminResponse) <> ''")
            ->andWhere('f.adminResponseDate IS NOT NULL')
            ->andWhere('f.adminResponseDate > :lastVisit')
            ->setParameter('user', $user)
            ->setParameter('lastVisit', $lastVisit)
            ->orderBy('f.adminResponseDate', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($feedbacks as $feedback) {
            if (!$feedback instanceof Feedback || !$feedback->getAdminResponseDate()) {
                continue;
            }

            $publication = $feedback->getPublication();

            $notifications[] = [
                'id' => 'dynamic-reply-' . $feedback->getIdFeedback(),
                'source' => 'dynamic',
                'type' => 'SUCCESS',
                'title' => 'Reponse a votre commentaire',
                'message' => sprintf(
                    'L administration a repondu a votre commentaire sur "%s".',
                    (string) $publication->getTitre()
                ),
                'date' => $feedback->getAdminResponseDate(),
                'read' => false,
                'actionUrl' => '/espace-client/publications/' . $publication->getId(),
                'actionLabel' => 'Voir la reponse',
            ];
        }

        usort(
            $notifications,
            static fn (array $left, array $right): int => $right['date'] <=> $left['date']
        );

        return $notifications;
    }

    private function findPreferredCategory(User $user): ?string
    {
        $row = $this->em->getRepository(Feedback::class)
            ->createQueryBuilder('f')
            ->join('f.publication', 'p')
            ->select('p.categorie AS categorie')
            ->addSelect('COUNT(f.idFeedback) AS HIDDEN feedbackCount')
            ->where('f.user = :user')
            ->andWhere('p.categorie IS NOT NULL')
            ->andWhere("p.categorie <> ''")
            ->setParameter('user', $user)
            ->groupBy('p.categorie')
            ->orderBy('feedbackCount', 'DESC')
            ->addOrderBy('p.categorie', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row)) {
            return null;
        }

        $category = trim((string) ($row['categorie'] ?? ''));

        return $category !== '' ? $category : null;
    }
}
