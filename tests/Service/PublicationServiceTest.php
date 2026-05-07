<?php

namespace App\Tests\Service;

use App\Entity\Publication\Publication;
use App\Entity\User\Feedback;
use App\Repository\PublicationRepository;
use App\Service\PublicationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PublicationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private PublicationRepository $publicationRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->publicationRepository = $this->createMock(PublicationRepository::class);
    }

    public function testCreatePublicationSetsPublicationDateWhenStatusIsPublished(): void
    {
        $service = $this->createService();
        $publication = $this->createPublication(Publication::STATUS_PUBLIE);

        $this->entityManager->expects(self::once())->method('persist')->with($publication);
        $this->entityManager->expects(self::once())->method('flush');

        $service->createPublication($publication);

        self::assertTrue($publication->isEstVisible());
        self::assertNotNull($publication->getDatePublication());
    }

    public function testUpdatePublicationClearsPublicationDateWhenStatusIsDraft(): void
    {
        $service = $this->createService();
        $publication = $this->createPublication(Publication::STATUS_BROUILLON);
        $publication->setEstVisible(true);
        $publication->setDatePublication(new \DateTimeImmutable('2026-05-01 10:00:00'));

        $this->entityManager->expects(self::once())->method('flush');

        $service->updatePublication($publication);

        self::assertFalse($publication->isEstVisible());
        self::assertNull($publication->getDatePublication());
    }

    public function testDeletePublicationRemovesEntityAndFlushes(): void
    {
        $service = $this->createService();
        $publication = $this->createPublication(Publication::STATUS_BROUILLON);

        $this->entityManager->expects(self::once())->method('remove')->with($publication);
        $this->entityManager->expects(self::once())->method('flush');

        $service->deletePublication($publication);
    }

    public function testPublicationCountsLikesDislikesAndComments(): void
    {
        $publication = $this->createPublication(Publication::STATUS_PUBLIE);

        $publication
            ->addFeedback($this->createFeedback('LIKE', 'Tres bien'))
            ->addFeedback($this->createFeedback('DISLIKE', null))
            ->addFeedback($this->createFeedback('LIKE', 'Article utile'));

        self::assertSame(2, $publication->getLikeCount());
        self::assertSame(1, $publication->getDislikeCount());
        self::assertSame(2, $publication->getCommentCount());
    }

    public function testPublicationCalculatesAverageRatingAndEngagementScore(): void
    {
        $publication = $this->createPublication(Publication::STATUS_PUBLIE);

        $publication
            ->addFeedback($this->createFeedback('RATING_5', 'Excellent'))
            ->addFeedback($this->createFeedback('RATING_3', 'Correct'))
            ->addFeedback($this->createFeedback('LIKE', null));

        self::assertSame(4.0, $publication->getAverageRating());
        self::assertSame(8, $publication->getEngagementScore());
    }

    private function createService(): PublicationService
    {
        return new PublicationService($this->entityManager, $this->publicationRepository);
    }

    private function createPublication(string $status): Publication
    {
        return (new Publication())
            ->setTitre('Publication test')
            ->setContenu('Contenu de publication pour les tests unitaires.')
            ->setCategorie(Publication::CATEGORY_FINANCE)
            ->setStatut($status);
    }

    private function createFeedback(?string $reaction, ?string $comment): Feedback
    {
        return (new Feedback())
            ->setTypeReaction($reaction)
            ->setCommentaire($comment)
            ->setDateFeedback(new \DateTimeImmutable('2026-05-01 10:00:00'));
    }
}
