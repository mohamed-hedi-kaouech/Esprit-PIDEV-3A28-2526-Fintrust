<?php

namespace App\Controller\Admin;

use App\Entity\User\Feedback;
use App\Entity\Publication\Publication;
use App\Form\Admin\PublicationFormType;
use App\Repository\PublicationRepository;
use App\Service\ExportService;
use App\Service\PdfPublicationService;
use App\Service\PublicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/publications', name: 'admin_publications_')]
#[IsGranted('ROLE_ADMIN')]
class PublicationController extends AbstractController
{
    public function __construct(
        private readonly PublicationService $publicationService,
        private readonly PublicationRepository $publicationRepo,
        private readonly ExportService $exportService,
        private readonly PdfPublicationService $pdfPublicationService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $search = trim((string) $request->query->get('search', ''));
        $categoryName = $request->query->get('category') !== null ? trim((string) $request->query->get('category')) : null;
        $sortBy = trim((string) $request->query->get('sort', 'newest'));

        $qb = $this->em->getRepository(Publication::class)->createQueryBuilder('p')
            ->leftJoin('p.feedbacks', 'f')
            ->addSelect("SUM(CASE WHEN f.commentaire IS NOT NULL AND TRIM(f.commentaire) <> '' THEN 1 ELSE 0 END) AS HIDDEN commentCount")
            ->addSelect("SUM(CASE WHEN UPPER(COALESCE(f.typeReaction, '')) = 'LIKE' THEN 1 ELSE 0 END) AS HIDDEN likeCount")
            ->addSelect("SUM(CASE WHEN UPPER(COALESCE(f.typeReaction, '')) = 'DISLIKE' THEN 1 ELSE 0 END) AS HIDDEN dislikeCount")
            ->addSelect("(
                (SUM(CASE WHEN f.commentaire IS NOT NULL AND TRIM(f.commentaire) <> '' THEN 1 ELSE 0 END) * 3)
                + (SUM(CASE WHEN UPPER(COALESCE(f.typeReaction, '')) = 'LIKE' THEN 1 ELSE 0 END) * 2)
                - SUM(CASE WHEN UPPER(COALESCE(f.typeReaction, '')) = 'DISLIKE' THEN 1 ELSE 0 END)
            ) AS HIDDEN engagementScore");

        if ($search) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($categoryName) {
            $qb->andWhere('p.categorie = :category')
                ->setParameter('category', $categoryName);
        }

        match ($sortBy) {
            'comments' => $qb->orderBy('commentCount', 'DESC')->addOrderBy('p.datePublication', 'DESC'),
            'likes' => $qb->orderBy('likeCount', 'DESC')->addOrderBy('p.datePublication', 'DESC'),
            'engagement' => $qb->orderBy('engagementScore', 'DESC')->addOrderBy('commentCount', 'DESC')->addOrderBy('p.datePublication', 'DESC'),
            default => $qb->orderBy('p.datePublication', 'DESC'),
        };

        $limit = 10;
        $qb->groupBy('p.id')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $publications = $qb->getQuery()->getResult();

        $countQb = $this->em->getRepository(Publication::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where($search ? 'p.titre LIKE :search OR p.contenu LIKE :search' : '1=1')
            ->andWhere($categoryName ? 'p.categorie = :category' : '1=1');

        if ($search) {
            $countQb->setParameter('search', '%' . $search . '%');
        }

        if ($categoryName) {
            $countQb->setParameter('category', $categoryName);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $totalPages = ceil($total / $limit);
        $categories = array_map(
            fn(array $row) => $row['categorie'],
            $this->em->getRepository(Publication::class)
                ->createQueryBuilder('p')
                ->select('DISTINCT p.categorie')
                ->orderBy('p.categorie')
                ->getQuery()
                ->getResult()
        );
        $stats = $this->publicationService->getStatistics();
        $feedbackStats = $this->publicationService->getFeedbackStatistics();
        $categoryStats = $this->publicationRepo->getCategoryCounts();
        $publicationTrend = $this->publicationService->getPublicationTrend(6);
        $categoryPerformance = $this->publicationService->getCategoryPerformanceStats();
        $feedbackInsights = $this->publicationService->getFeedbackInsightStats();
        $weeklyPublicationStats = $this->publicationService->getWeeklyPublicationStats();
        $topPublications = $this->publicationService->getTopPublicationsByEngagement(5);
        $trendCounts = array_map(static fn (array $point): int => (int) $point['count'], $publicationTrend);
        $trendMax = $trendCounts !== [] ? max($trendCounts) : 1;

        return $this->render('admin/publication/index.html.twig', [
            'publications' => $publications,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'search' => $search,
            'categories' => $categories,
            'current_category' => $categoryName,
            'sort_by' => $sortBy,
            'stats' => $stats,
            'feedback_stats' => $feedbackStats,
            'category_stats' => $categoryStats,
            'publication_trend' => $publicationTrend,
            'category_performance' => $categoryPerformance,
            'feedback_insights' => $feedbackInsights,
            'weekly_publication_stats' => $weeklyPublicationStats,
            'top_publications' => $topPublications,
            'trend_max' => $trendMax,
        ]);
    }

    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $categoryName = $request->query->get('category') !== null ? trim((string) $request->query->get('category')) : null;

        $publications = $this->publicationRepo->createFilteredQueryBuilder($search, $categoryName)
            ->orderBy('p.datePublication', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->exportService->exportPublicationsCsv($publications);
    }

    #[Route('/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $categoryName = $request->query->get('category') !== null ? trim((string) $request->query->get('category')) : null;

        $publications = $this->publicationRepo->createFilteredQueryBuilder($search, $categoryName)
            ->orderBy('p.datePublication', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->exportService->exportPublicationsPdfHtml($publications);
    }

    #[Route('/{id}/export/pdf', name: 'export_single_pdf', methods: ['GET'])]
    public function exportSinglePublicationPdf(Publication $publication): Response
    {
        return $this->pdfPublicationService->generatePublicationPdf($publication);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $publication = new Publication();
        $form = $this->createForm(PublicationFormType::class, $publication);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->publicationService->createPublication($publication);
            $this->addFlash('success', 'Publication creee avec succes!');
            return $this->redirectToRoute('admin_publications_index');
        }

        return $this->render('admin/publication/create.html.twig', [
            'form' => $form,
            'publication' => $publication,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Publication $publication, Request $request): Response
    {
        $form = $this->createForm(PublicationFormType::class, $publication);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->publicationService->updatePublication($publication);
            $this->addFlash('success', 'Publication mise a jour avec succes!');
            return $this->redirectToRoute('admin_publications_index');
        }

        return $this->render('admin/publication/edit.html.twig', [
            'form' => $form,
            'publication' => $publication,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Publication $publication, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete' . $publication->getId(), (string) $request->request->get('_token'))) {
            $this->publicationService->deletePublication($publication);
            $this->addFlash('success', 'Publication supprimee avec succes!');
        }

        return $this->redirectToRoute('admin_publications_index');
    }

    #[Route('/{id}/comments', name: 'comments', methods: ['GET'])]
    public function showComments(Publication $publication): Response
    {
        $allFeedbacks = $publication->getFeedbacks();
        $feedbacks = $allFeedbacks
            ->filter(fn($f) => $f->getCommentaire() !== null && trim((string) $f->getCommentaire()) !== '')
            ->toArray();

        usort($feedbacks, static fn(Feedback $left, Feedback $right) => ($right->getDateFeedback()?->getTimestamp() ?? 0) <=> ($left->getDateFeedback()?->getTimestamp() ?? 0));

        $commentCount = $publication->getCommentCount();
        $likeCount = $publication->getLikeCount();
        $dislikeCount = $publication->getDislikeCount();
        $engagementScore = $publication->getEngagementScore();
        $adminReplyCount = count(array_filter($feedbacks, static fn(Feedback $feedback) => $feedback->getAdminResponse() !== null && trim($feedback->getAdminResponse()) !== ''));

        return $this->render('admin/publication/comments.html.twig', [
            'publication' => $publication,
            'feedbacks' => $feedbacks,
            'commentCount' => $commentCount,
            'likeCount' => $likeCount,
            'dislikeCount' => $dislikeCount,
            'engagementScore' => $engagementScore,
            'adminReplyCount' => $adminReplyCount,
        ]);
    }

    #[Route('/{id}/feedback/{feedbackId}/reply', name: 'reply_feedback', methods: ['POST'])]
    public function replyFeedback(Publication $publication, int $feedbackId, Request $request): Response
    {
        $feedback = $this->em->getRepository(Feedback::class)->find($feedbackId);
        if (!$feedback || $feedback->getPublication()->getId() !== $publication->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('reply_feedback_' . $feedback->getIdFeedback(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');
            return $this->redirectToRoute('admin_publications_comments', ['id' => $publication->getId()]);
        }

        $reply = trim((string) $request->request->get('admin_response', ''));

        if ($reply === '') {
            $this->addFlash('error', 'La reponse admin ne peut pas etre vide.');
            return $this->redirectToRoute('admin_publications_comments', ['id' => $publication->getId()]);
        }

        $feedback
            ->setAdminResponse($reply)
            ->setAdminResponseDate(new \DateTime());

        $this->em->flush();
        $this->addFlash('success', 'Reponse admin enregistree avec succes.');

        return $this->redirectToRoute('admin_publications_comments', ['id' => $publication->getId()]);
    }

    #[Route('/{id}/feedback/{feedbackId}/delete-reply', name: 'delete_reply', methods: ['POST'])]
    public function deleteReply(Publication $publication, int $feedbackId, Request $request): Response
    {
        $feedback = $this->em->getRepository(Feedback::class)->find($feedbackId);

        if (!$feedback || $feedback->getPublication()->getId() !== $publication->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete_reply_' . $feedback->getIdFeedback(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('admin_publications_comments', ['id' => $publication->getId()]);
        }

        $feedback
            ->setAdminResponse(null)
            ->setAdminResponseDate(null);

        $this->em->flush();
        $this->addFlash('success', 'La reponse admin a ete supprimee.');

        return $this->redirectToRoute('admin_publications_comments', ['id' => $publication->getId()]);
    }
}
