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
    #[Route('/ClientSubscriptionslist', name: 'Client_subscription_list', methods: ['GET','POST'])]
    public function list(
        Request $request,
        ProductSubscriptionRepository $subscriptionRepo
    ): Response {
        $user = $this->getUser();
        $clientId     = $user->getId(); // new filter
        $typeFilter   = $request->query->get('type', '');
        $statusFilter = $request->query->get('status', '');
        $search       = trim($request->query->get('search', ''));

        $qb = $subscriptionRepo->createQueryBuilder('s')
            ->join('s.clientUser', 'c')
            ->join('s.productObj', 'p');

        // Filter by clientId
        if ($clientId > 0) {
            $qb->andWhere('c.id = :clientId')
                ->setParameter('clientId', $clientId);
        }

        // Apply other filters
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
                ->setParameter('search', '%'.$search.'%');
        }

        $subscriptions = $qb->getQuery()->getResult();

        // Map to arrays for Twig
        $subscriptionsView = array_map(function($s) {
            return [
                'subscriptionId'   => $s->getSubscriptionId(),
                'clientLastName'   => $s->getClientUser()->getNom(),
                'productCategory'  => $s->getProductObj()->getCategory(),
                'type'             => $s->getType(),
                'subscriptionDate' => $s->getSubscriptionDate(),
                'expirationDate'   => $s->getExpirationDate(),
                'status'           => $s->getStatus(),
            ];
        }, $subscriptions);

        // Stats
        $total        = count($subscriptions);
        $active       = count(array_filter($subscriptions, fn($s)=>$s->getStatus()=='ACTIVE'));
        $draft        = count(array_filter($subscriptions, fn($s)=>$s->getStatus()=='DRAFT'));
        $suspended    = count(array_filter($subscriptions, fn($s)=>$s->getStatus()=='SUSPENDED'));
        $closed       = count(array_filter($subscriptions, fn($s)=>$s->getStatus()=='CLOSED'));
        $expiringSoon = count(array_filter($subscriptions, fn($s)=>$s->getExpirationDate() <= new \DateTime('+30 days')));

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
            'clientId'               => $clientId, // pass to Twig if needed
        ]);
    }



    #[Route('/subscriptiondelete/{id}', name: 'Client_subscriptiondelete')]
    public function subscriptiondelete($id, ProductSubscriptionRepository $repository, EntityManagerInterface $em): Response
    {
        $subproduct = $repository->find($id);

        if (!$subproduct) {
            throw $this->createNotFoundException('Product not found');
        }

        $em->remove($subproduct);
        $em->flush();
        $this->addFlash('success', 'Subscription Produit supprimé avec succès');
        return $this->redirectToRoute('Client_subscription_list');
    }


}
