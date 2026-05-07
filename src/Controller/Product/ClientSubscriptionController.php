<?php

namespace App\Controller\Product;

use App\Entity\User\User;
use App\Repository\Product\ProductSubscriptionRepository;
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
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $clientId     = $user->getId();
        $typeFilter   = (string) $request->query->get('type', '');
        $statusFilter = (string) $request->query->get('status', '');
        $search       = trim((string) $request->query->get('search', ''));

        $subscriptions = $subscriptionRepo->findByFilters(
            $typeFilter,
            $statusFilter,
            $search
        );

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
