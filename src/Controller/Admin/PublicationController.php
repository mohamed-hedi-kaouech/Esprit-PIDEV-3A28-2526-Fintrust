<?php

namespace App\Controller\Admin;

use App\Entity\Publication\Publication;
use App\Form\Admin\PublicationFeedbackFormType;
use App\Form\Admin\PublicationFormType;
use App\Repository\PublicationRepository;
use App\Service\ExportService;
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
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $search = $request->query->get('search', '');
        $categoryName = $request->query->get('category', null);
        $sortBy = $request->query->get('sort', 'newest');

        // Récupérer publsiations (brouillons et publié)
        $qb = $this->em->getRepository(Publication::class)->createQueryBuilder('p');
        
        if ($search) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($categoryName) {
            $qb->andWhere('p.categorie = :category')
                ->setParameter('category', $categoryName);
        }

        match ($sortBy) {
            'views', 'comments', 'likes' => $qb->orderBy('p.datePublication', 'DESC'),
            default => $qb->orderBy('p.datePublication', 'DESC'),
        };

        $limit = 10;
        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
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
        $trendMax = max(array_map(fn(array $point) => $point['count'], $publicationTrend)) ?: 1;

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
            'trend_max' => $trendMax,
        ]);
    }

    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $search = $request->query->get('search', '');
        $categoryName = $request->query->get('category', null);

        $publications = $this->publicationRepo->createFilteredQueryBuilder($search, $categoryName)
            ->orderBy('p.datePublication', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->exportService->exportPublicationsCsv($publications);
    }

    #[Route('/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $search = $request->query->get('search', '');
        $categoryName = $request->query->get('category', null);

        $publications = $this->publicationRepo->createFilteredQueryBuilder($search, $categoryName)
            ->orderBy('p.datePublication', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->exportService->exportPublicationsPdfHtml($publications);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $publication = new Publication();
        $form = $this->createForm(PublicationFormType::class, $publication);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->publicationService->createPublication($publication);
            $this->addFlash('success', 'Publication créée avec succès!');
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
            $this->addFlash('success', 'Publication mise à jour avec succès!');
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
        if ($this->isCsrfTokenValid('delete' . $publication->getId(), $request->request->get('_token'))) {
            $this->publicationService->deletePublication($publication);
            $this->addFlash('success', 'Publication supprimée avec succès!');
        }

        return $this->redirectToRoute('admin_publications_index');
    }

    #[Route('/{id}/comments', name: 'comments', methods: ['GET'])]
    public function showComments(Publication $publication, Request $request): Response
    {
        $feedbacks = $publication->getFeedbacks()
            ->filter(fn($f) => $f->getCommentaire() !== null && trim((string) $f->getCommentaire()) !== '')
            ->toArray();

        $commentCount = count($feedbacks);
        $likeCount = 0;
        $dislikeCount = 0;

        foreach ($feedbacks as $feedback) {
            $type = strtoupper((string) $feedback->getTypeReaction());
            if ($type === 'LIKE') {
                $likeCount++;
            } elseif ($type === 'DISLIKE') {
                $dislikeCount++;
            }
        }

        return $this->render('admin/publication/comments.html.twig', [
            'publication' => $publication,
            'feedbacks' => $feedbacks,
            'commentCount' => $commentCount,
            'likeCount' => $likeCount,
            'dislikeCount' => $dislikeCount,
        ]);
    }

    #[Route('/{id}/feedback/{feedbackId}/reply', name: 'reply_feedback', methods: ['POST'])]
    public function replyFeedback(Publication $publication, int $feedbackId, Request $request): Response
    {
        $feedback = $this->em->find(\App\Entity\Publication\PublicationFeedback::class, $feedbackId);
        
        if (!$feedback || $feedback->getPublication()->getId() !== $publication->getId()) {
            throw $this->createNotFoundException();
        }

        $reply = new \App\Entity\Publication\PublicationFeedback();
        $form = $this->createForm(PublicationFeedbackFormType::class, $reply);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->publicationService->replyToComment($feedback, $this->getUser(), $reply->getContent());
            $this->addFlash('success', 'Réponse ajoutée avec succès!');
        }

        return $this->redirectToRoute('admin_publications_comments', ['id' => $publication->getId()]);
    }
}
