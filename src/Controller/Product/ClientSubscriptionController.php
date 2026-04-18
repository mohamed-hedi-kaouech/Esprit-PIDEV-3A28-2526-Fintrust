<?php

namespace App\Controller\Product;

use App\Entity\Product\ProductSubscription;
use App\Repository\Product\ProductRepository;
use App\Repository\Product\ProductSubscriptionRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ClientSubscriptionController extends AbstractController
{
    #[Route('/ClientSubscriptionslist', name: 'Client_subscription_list', methods: ['GET', 'POST'])]
    public function list(
        Request $request,
        ProductSubscriptionRepository $subscriptionRepo
    ): Response {
        $user         = $this->getUser();
        $clientId     = $user->getId();
        $typeFilter   = $request->query->get('type', '');
        $statusFilter = $request->query->get('status', '');
        $search       = trim($request->query->get('search', ''));

        $qb = $subscriptionRepo->createQueryBuilder('s')
            ->join('s.clientUser', 'c')
            ->join('s.productObj', 'p');

        if ($clientId > 0) {
            $qb->andWhere('c.id = :clientId')
                ->setParameter('clientId', $clientId);
        }

        if ($typeFilter !== '') {
            $qb->andWhere('s.type = :type')
                ->setParameter('type', $typeFilter);
        }

        if ($statusFilter !== '') {
            $qb->andWhere('s.status = :status')
                ->setParameter('status', $statusFilter);
        }

        if ($search !== '') {
            $qb->andWhere('p.category LIKE :search OR c.nom LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $subscriptions = $qb->getQuery()->getResult();

        // Map to arrays for Twig
        $subscriptionsView = array_map(function ($s) {
            $now        = new \DateTime();
            $expiration = $s->getExpirationDate();
            $daysLeft   = $expiration ? (int)$now->diff($expiration)->days : null;

            return [
                'subscriptionId'       => $s->getSubscriptionId(),
                'clientLastName'       => $s->getClientUser()->getNom(),
                'productCategory'      => $s->getProductObj()->getCategory(),
                'type'                 => $s->getType(),
                'subscriptionDate'     => $s->getSubscriptionDate(),
                'expirationDate'       => $expiration,
                'status'               => $s->getStatus(),
                'daysUntilExpiration'  => $daysLeft,
            ];
        }, $subscriptions);

        // Stats
        $total        = count($subscriptions);
        $active       = count(array_filter($subscriptions, fn($s) => $s->getStatus() === 'ACTIVE'));
        $draft        = count(array_filter($subscriptions, fn($s) => $s->getStatus() === 'DRAFT'));
        $suspended    = count(array_filter($subscriptions, fn($s) => $s->getStatus() === 'SUSPENDED'));
        $closed       = count(array_filter($subscriptions, fn($s) => $s->getStatus() === 'CLOSED'));
        $expiringSoon = count(array_filter($subscriptions, fn($s) => $s->getExpirationDate() <= new \DateTime('+30 days')));

        return $this->render('html/Product/Client/SubscriptionList.html.twig', [
            'subscriptions'          => $subscriptionsView,
            'totalSubscriptions'     => $total,
            'activeSubscriptions'    => $active,
            'draftSubscriptions'     => $draft,
            'suspendedSubscriptions' => $suspended,
            'closedSubscriptions'    => $closed,
            'expiringSoon'           => $expiringSoon,
            'typeFilter'             => $typeFilter,
            'statusFilter'           => $statusFilter,
            'search'                 => $search,
            'clientId'               => $clientId,
        ]);
    }

    #[Route('/subscriptiondelete/{id}', name: 'Client_subscriptiondelete')]
    public function subscriptiondelete(
        int $id,
        ProductSubscriptionRepository $repository,
        EntityManagerInterface $em
    ): Response {
        $subproduct = $repository->find($id);

        if (!$subproduct) {
            return $this->redirectToRoute('Client_subscription_list', [
                'swal' => 'error',
                'msg'  => 'Abonnement introuvable.',
            ]);
        }

        // Ownership check — prevent deleting another user's subscription
        if ($subproduct->getClientUser() !== $this->getUser()) {
            return $this->redirectToRoute('Client_subscription_list', [
                'swal' => 'error',
                'msg'  => 'Action non autorisée.',
            ]);
        }

        $em->remove($subproduct);
        $em->flush();

        return $this->redirectToRoute('Client_subscription_list', [
            'swal' => 'success',
            'msg'  => 'Abonnement annulé avec succès.',
        ]);
    }
}