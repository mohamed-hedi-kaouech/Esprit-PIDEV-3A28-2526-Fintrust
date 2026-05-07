<?php

namespace App\Controller\Front;

use App\Entity\Publication\Publication;
use App\Entity\User\Feedback;
use App\Entity\User\User;
use App\Repository\PublicationRepository;
use App\Service\CommentModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/publications', name: 'front_publications_')]
class PublicationController extends AbstractController
{
    public function __construct(
        private readonly PublicationRepository $publicationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CommentModerationService $commentModerationService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $selectedCategory = trim((string) $request->query->get('category', ''));
        $searchTerm = trim((string) $request->query->get('keyword', ''));
        $sortBy = trim((string) $request->query->get('sort', 'recentes')) ?: 'recentes';

        return $this->render('front/client/publications.html.twig', [
            'publications' => $this->publicationRepository->findPublishedWithStats(
                $selectedCategory !== '' ? $selectedCategory : null,
                $searchTerm !== '' ? $searchTerm : null,
                $sortBy
            ),
            'categories' => $this->publicationRepository->getDistinctCategories(),
            'selectedCategory' => $selectedCategory,
            'searchTerm' => $searchTerm,
            'sortBy' => $sortBy,
        ]);
    }

    #[Route('/{id}', name: 'view', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function view(Publication $publication, Request $request): Response
    {
        if ($publication->getStatut() !== Publication::STATUS_PUBLIE) {
            throw $this->createNotFoundException('Publication introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $commentNotice = null;

        $commentForm = $this->createFormBuilder()
            ->add('commentaire', TextareaType::class, [
                'required' => true,
                'label' => 'Votre commentaire',
            ])
            ->add('rating', ChoiceType::class, [
                'required' => true,
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    '5 etoiles' => 5,
                    '4 etoiles' => 4,
                    '3 etoiles' => 3,
                    '2 etoiles' => 2,
                    '1 etoile' => 1,
                ],
                'data' => 5,
            ])
            ->getForm();

        $commentForm->handleRequest($request);

        if ($commentForm->isSubmitted() && $commentForm->isValid()) {
            $data = $commentForm->getData();
            $commentText = trim((string) ($data['commentaire'] ?? ''));
            $analysis = $this->commentModerationService->handleDecision(
                $commentText,
                $user,
                'Publication #' . $publication->getId()
            );

            if ($analysis['decision'] !== 'accept') {
                $commentForm->get('commentaire')->addError(new FormError(
                    'Votre commentaire contient un langage insultant, violent ou inapproprie.'
                ));
                $commentNotice = $this->buildCommentNotice($analysis);
            } else {
                $feedback = new Feedback();
                $feedback->setPublication($publication);
                $feedback->setUser($user);
                $feedback->setIdUser($user->getId());
                $feedback->setCommentaire($commentText);
                $feedback->setTypeReaction('RATING_' . (int) ($data['rating'] ?? 5));
                $feedback->setDateFeedback(new \DateTimeImmutable());

                $this->entityManager->persist($feedback);
                $this->entityManager->flush();

                $this->addFlash('success', 'Votre avis a ete ajoute avec succes.');

                return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
            }
        }

        $comments = $publication->getFeedbacks()->toArray();
        usort(
            $comments,
            static fn (Feedback $left, Feedback $right): int => ($right->getDateFeedback()?->getTimestamp() ?? 0) <=> ($left->getDateFeedback()?->getTimestamp() ?? 0)
        );

        return $this->render('front/client/publication_detail.html.twig', [
            'publication' => $publication,
            'comments' => $comments,
            'likes' => $publication->getLikeCount(),
            'dislikes' => $publication->getDislikeCount(),
            'averageRating' => $publication->getAverageRating(),
            'commentForm' => $commentForm,
            'commentNotice' => $commentNotice,
        ]);
    }

    #[Route('/{id}/react/{reaction}', name: 'react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function react(Publication $publication, string $reaction, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('publication_react_' . $publication->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $reaction = strtoupper($reaction);
        if (!in_array($reaction, ['LIKE', 'DISLIKE'], true)) {
            $this->addFlash('error', 'Reaction invalide.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $feedback = new Feedback();
        $feedback->setPublication($publication);
        $feedback->setUser($user);
        $feedback->setIdUser($user->getId());
        $feedback->setTypeReaction($reaction);
        $feedback->setDateFeedback(new \DateTimeImmutable());

        $this->entityManager->persist($feedback);
        $this->entityManager->flush();

        $this->addFlash('success', $reaction === 'LIKE' ? 'Merci pour votre like.' : 'Votre retour a bien ete pris en compte.');

        return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
    }

    #[Route('/{id}/comment/{feedbackId}/edit', name: 'comment_edit', methods: ['POST'], requirements: ['id' => '\d+', 'feedbackId' => '\d+'])]
    public function editComment(Publication $publication, int $feedbackId, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var Feedback|null $feedback */
        $feedback = $this->entityManager->getRepository(Feedback::class)->find($feedbackId);

        if (!$feedback || $feedback->getPublication()->getId() !== $publication->getId() || $feedback->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Commentaire introuvable.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        if (!$this->isCsrfTokenValid('edit_comment_' . $feedbackId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $commentText = trim((string) $request->request->get('commentaire', ''));
        $analysis = $this->commentModerationService->handleDecision(
            $commentText,
            $user,
            'Edition commentaire publication #' . $publication->getId()
        );

        if ($analysis['decision'] !== 'accept') {
            $this->addFlash('error', 'Commentaire bloque: langage insultant, violent ou inapproprie detecte.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $feedback->setCommentaire($commentText);
        $feedback->setTypeReaction('RATING_' . max(1, min(5, (int) $request->request->get('rating', 5))));
        $feedback->setDateFeedback(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->addFlash('success', 'Votre commentaire a ete mis a jour.');

        return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
    }

    #[Route('/{id}/comment/{feedbackId}/delete', name: 'comment_delete', methods: ['POST'], requirements: ['id' => '\d+', 'feedbackId' => '\d+'])]
    public function deleteComment(Publication $publication, int $feedbackId, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var Feedback|null $feedback */
        $feedback = $this->entityManager->getRepository(Feedback::class)->find($feedbackId);

        if (!$feedback || $feedback->getPublication()->getId() !== $publication->getId() || $feedback->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'Commentaire introuvable.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        if (!$this->isCsrfTokenValid('delete_comment_' . $feedbackId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $this->entityManager->remove($feedback);
        $this->entityManager->flush();

        $this->addFlash('success', 'Votre commentaire a ete supprime.');

        return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
    }

    /**
     * @param array<string, string> $analysis
     * @return array{type:string,message:string}
     */
    private function buildCommentNotice(array $analysis): array
    {
        if ($analysis['decision'] === 'reject' || $analysis['severity'] === 'high') {
            return [
                'type' => 'danger',
                'message' => 'Commentaire refuse: les insultes, menaces, propos haineux ou violents ne sont pas acceptes.',
            ];
        }

        return [
            'type' => 'warning',
            'message' => 'Commentaire bloque: le langage injurieux ou agressif n est pas autorise sur la plateforme.',
        ];
    }
}
