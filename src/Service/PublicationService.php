<?php

namespace App\Service;

use App\Entity\Publication\Publication;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;

class PublicationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublicationRepository $publicationRepo,
    ) {}

    public function createPublication(Publication $publication): void
    {
        $this->synchronizePublicationStatus($publication);

        if ($publication->getStatut() === Publication::STATUS_PUBLIE) {
            $publication->setDatePublication(new \DateTime());
        }

        $this->em->persist($publication);
        $this->em->flush();
    }

    public function updatePublication(Publication $publication): void
    {
        $this->synchronizePublicationStatus($publication);

        if ($publication->getStatut() === Publication::STATUS_PUBLIE && !$publication->getDatePublication()) {
            $publication->setDatePublication(new \DateTime());
        }

        if ($publication->getStatut() !== Publication::STATUS_PUBLIE) {
            $publication->setDatePublication(null);
        }

        $this->em->flush();
    }

    public function deletePublication(Publication $publication): void
    {
        $this->em->remove($publication);
        $this->em->flush();
    }

    public function getStatistics(): array
    {
        $totalPublications = $this->publicationRepo->count([]);
        $publishedPublications = $this->publicationRepo->countPublished();
        $totalFeedbacks = (int) $this->em->getRepository(\App\Entity\User\Feedback::class)
            ->createQueryBuilder('f')
            ->select('COUNT(f.idFeedback)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_publications' => $totalPublications,
            'published_publications' => $publishedPublications,
            'total_feedbacks' => $totalFeedbacks,
            'total_comments' => $totalFeedbacks,
        ];
    }

    public function getFeedbackStatistics(): array
    {
        $repo = $this->em->getRepository(\App\Entity\User\Feedback::class);

        $totalComments = (int) $repo->createQueryBuilder('f')
            ->select('COUNT(f.idFeedback)')
            ->where('f.commentaire IS NOT NULL')
            ->andWhere("f.commentaire <> ''")
            ->getQuery()
            ->getSingleScalarResult();

        $totalLikes = (int) $repo->createQueryBuilder('f')
            ->select('COUNT(f.idFeedback)')
            ->where('UPPER(f.typeReaction) = :like')
            ->setParameter('like', 'LIKE')
            ->getQuery()
            ->getSingleScalarResult();

        $totalDislikes = (int) $repo->createQueryBuilder('f')
            ->select('COUNT(f.idFeedback)')
            ->where('UPPER(f.typeReaction) = :dislike')
            ->setParameter('dislike', 'DISLIKE')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'comments' => $totalComments,
            'likes' => $totalLikes,
            'dislikes' => $totalDislikes,
        ];
    }

    public function getPublicationTrend(int $months = 6): array
    {
        $startDate = (new \DateTimeImmutable())
            ->modify('-' . $months . ' months')
            ->modify('first day of this month')
            ->setTime(0, 0, 0);

        $sql = 'SELECT YEAR(date_publication) AS year, MONTH(date_publication) AS month, COUNT(id_publication) AS publication_count FROM publication WHERE date_publication IS NOT NULL AND date_publication >= ? GROUP BY year, month ORDER BY year ASC, month ASC';
        $stmt = $this->em->getConnection()->executeQuery($sql, [$startDate->format('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAllAssociative();

        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = (new \DateTimeImmutable())->modify('-' . $i . ' months');
            $label = $date->format('M Y');
            $trend[$label] = 0;
        }

        foreach ($rows as $row) {
            $month = (int) $row['month'];
            $year = (int) $row['year'];
            $date = new \DateTimeImmutable("$year-$month-01");
            $label = $date->format('M Y');
            $trend[$label] = (int) $row['publication_count'];
        }

        return array_map(fn($count, $label) => ['label' => $label, 'count' => $count], $trend, array_keys($trend));
    }

    public function getCategoryPerformanceStats(): array
    {
        $sql = <<<SQL
            SELECT
                COALESCE(NULLIF(TRIM(p.categorie), ''), 'Non definie') AS category_name,
                COUNT(DISTINCT p.id_publication) AS publication_count,
                SUM(CASE WHEN f.commentaire IS NOT NULL AND TRIM(f.commentaire) <> '' THEN 1 ELSE 0 END) AS comment_count,
                SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'LIKE' THEN 1 ELSE 0 END) AS like_count,
                SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'DISLIKE' THEN 1 ELSE 0 END) AS dislike_count
            FROM publication p
            LEFT JOIN feedback f ON f.id_publication = p.id_publication
            GROUP BY p.categorie
            ORDER BY publication_count DESC, like_count DESC, comment_count DESC
        SQL;

        return $this->normalizeCategoryStatRows(
            $this->em->getConnection()->executeQuery($sql)->fetchAllAssociative()
        );
    }

    public function getFeedbackInsightStats(): array
    {
        $feedbackStats = $this->getFeedbackStatistics();
        $totalSignals = $feedbackStats['comments'] + $feedbackStats['likes'] + $feedbackStats['dislikes'];
        $positiveRate = $totalSignals > 0 ? (int) round(($feedbackStats['likes'] / $totalSignals) * 100) : 0;
        $negativeRate = $totalSignals > 0 ? (int) round(($feedbackStats['dislikes'] / $totalSignals) * 100) : 0;
        $commentRate = $totalSignals > 0 ? (int) round(($feedbackStats['comments'] / $totalSignals) * 100) : 0;

        $sql = <<<SQL
            SELECT
                COALESCE(NULLIF(TRIM(p.categorie), ''), 'Non definie') AS category_name,
                SUM(CASE WHEN f.commentaire IS NOT NULL AND TRIM(f.commentaire) <> '' THEN 1 ELSE 0 END) AS comment_count,
                SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'LIKE' THEN 1 ELSE 0 END) AS like_count,
                SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'DISLIKE' THEN 1 ELSE 0 END) AS dislike_count
            FROM publication p
            INNER JOIN feedback f ON f.id_publication = p.id_publication
            GROUP BY p.categorie
            ORDER BY
                (
                    SUM(CASE WHEN f.commentaire IS NOT NULL AND TRIM(f.commentaire) <> '' THEN 1 ELSE 0 END)
                    + SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'LIKE' THEN 1 ELSE 0 END)
                    + SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'DISLIKE' THEN 1 ELSE 0 END)
                ) DESC,
                SUM(CASE WHEN UPPER(COALESCE(f.type_reaction, '')) = 'LIKE' THEN 1 ELSE 0 END) DESC
            LIMIT 5
        SQL;

        $categories = array_map(static function (array $row) {
            $comments = (int) $row['comments'];
            $likes = (int) $row['likes'];
            $dislikes = (int) $row['dislikes'];

            return [
                'category' => $row['category'],
                'comments' => $comments,
                'likes' => $likes,
                'dislikes' => $dislikes,
                'total' => $comments + $likes + $dislikes,
            ];
        }, array_slice($this->normalizeCategoryStatRows(
            $this->em->getConnection()->executeQuery($sql)->fetchAllAssociative()
        ), 0, 5));

        return [
            'totals' => $feedbackStats,
            'total_signals' => $totalSignals,
            'positive_rate' => $positiveRate,
            'negative_rate' => $negativeRate,
            'comment_rate' => $commentRate,
            'top_feedback_categories' => $categories,
        ];
    }

    public function getWeeklyPublicationStats(): array
    {
        $sql = <<<SQL
            SELECT DAYOFWEEK(date_publication) AS weekday_number, COUNT(id_publication) AS publication_count
            FROM publication
            WHERE date_publication IS NOT NULL
            GROUP BY DAYOFWEEK(date_publication)
        SQL;

        $rows = $this->em->getConnection()->executeQuery($sql)->fetchAllAssociative();
        $countByDay = [
            2 => 0,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 0,
            1 => 0,
        ];

        foreach ($rows as $row) {
            $countByDay[(int) $row['weekday_number']] = (int) $row['publication_count'];
        }

        $labels = [
            2 => 'Lundi',
            3 => 'Mardi',
            4 => 'Mercredi',
            5 => 'Jeudi',
            6 => 'Vendredi',
            7 => 'Samedi',
            1 => 'Dimanche',
        ];

        $maxCount = max($countByDay) ?: 1;
        $weeklyStats = [];

        foreach ($labels as $dayNumber => $label) {
            $count = $countByDay[$dayNumber];
            $weeklyStats[] = [
                'label' => $label,
                'count' => $count,
                'ratio' => (int) round(($count / $maxCount) * 100),
            ];
        }

        return $weeklyStats;
    }

    private function synchronizePublicationStatus(Publication $publication): void
    {
        if ($publication->getStatut() === Publication::STATUS_PUBLIE) {
            $publication->setEstVisible(true);

            return;
        }

        $publication->setEstVisible(false);
    }

    private function normalizeCategoryStatRows(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $label = $this->normalizeCategoryLabel($row['category_name'] ?? null);

            if (!isset($grouped[$label])) {
                $grouped[$label] = [
                    'category' => $label,
                    'publications' => 0,
                    'comments' => 0,
                    'likes' => 0,
                    'dislikes' => 0,
                ];
            }

            $grouped[$label]['publications'] += (int) ($row['publication_count'] ?? 0);
            $grouped[$label]['comments'] += (int) ($row['comment_count'] ?? 0);
            $grouped[$label]['likes'] += (int) ($row['like_count'] ?? 0);
            $grouped[$label]['dislikes'] += (int) ($row['dislike_count'] ?? 0);
        }

        $maxPublications = 1;
        $maxEngagement = 1;

        foreach ($grouped as $item) {
            $engagement = $item['comments'] + $item['likes'] + $item['dislikes'];
            $maxPublications = max($maxPublications, $item['publications']);
            $maxEngagement = max($maxEngagement, $engagement);
        }

        usort($grouped, static function (array $left, array $right) {
            return [$right['publications'], $right['likes'], $right['comments']] <=> [$left['publications'], $left['likes'], $left['comments']];
        });

        return array_map(static function (array $item) use ($maxPublications, $maxEngagement) {
            $engagement = $item['comments'] + $item['likes'] + $item['dislikes'];

            return [
                'category' => $item['category'],
                'publications' => $item['publications'],
                'comments' => $item['comments'],
                'likes' => $item['likes'],
                'dislikes' => $item['dislikes'],
                'engagement' => $engagement,
                'publication_ratio' => (int) round(($item['publications'] / $maxPublications) * 100),
                'engagement_ratio' => (int) round(($engagement / $maxEngagement) * 100),
            ];
        }, $grouped);
    }

    private function normalizeCategoryLabel(?string $category): string
    {
        $value = trim((string) $category);

        if ($value === '') {
            return 'Non definie';
        }

        $upper = strtoupper($value);

        return match ($upper) {
            'ACTUALITE', 'ACTUALITÉ', 'ACTUALIT?', 'ACTUALITE?' => 'Actualité',
            'ASSURANCE' => 'Assurance',
            'INFORMATION' => 'Information',
            'FINANCE' => 'Finance',
            'TECH' => 'Tech',
            'CYBERSECURITE' => 'Cybersécurité',
            'TRADING ET INVESTISSEMENT' => 'Trading et Investissement',
            default => ucfirst(strtolower($value)),
        };
    }
}
