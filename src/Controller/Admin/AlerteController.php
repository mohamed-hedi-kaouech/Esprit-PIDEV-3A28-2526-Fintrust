<?php

namespace App\Controller\Admin;

use App\Entity\Categorie\Alerte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

#[Route('/admin/alerte', name: 'admin_alerte_')]
class AlerteController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $alertes = $this->entityManager->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->leftJoin('a.categorie', 'c')
            ->addSelect('c')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/alerte/list.html.twig', [
            'alertes' => $alertes,
        ]);
    }

    #[Route('/mark-read/{idAlerte}', name: 'mark_read', methods: ['POST'])]
    public function markRead(Alerte $alerte, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mark_read' . $alerte->getIdAlerte(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_alerte_list');
        }

        $alerte->setRead(true);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alerte marquée comme lue.');
        return $this->redirectToRoute('admin_alerte_list');
    }

    #[Route('/mark-unread/{idAlerte}', name: 'mark_unread', methods: ['POST'])]
    public function markUnread(Alerte $alerte, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mark_unread' . $alerte->getIdAlerte(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_alerte_list');
        }

        $alerte->setRead(false);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alerte marquée comme non lue.');
        return $this->redirectToRoute('admin_alerte_list');
    }

    #[Route('/delete/{idAlerte}', name: 'delete', methods: ['POST'])]
    public function delete(Alerte $alerte, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $alerte->getIdAlerte(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_alerte_list');
        }

        $this->entityManager->remove($alerte);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alerte supprimée avec succès.');
        return $this->redirectToRoute('admin_alerte_list');
    }
}