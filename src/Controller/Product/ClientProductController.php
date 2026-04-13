<?php

namespace App\Controller\Product;

use App\Entity\Product\ProductSubscription;
use App\Repository\Product\ProductRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use phpDocumentor\Reflection\Types\Integer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientProductController extends AbstractController
{
    #[Route('/Product/ClientList', name: 'Client_product_list')]
    public function list(Request $request, ProductRepository $repo): Response
    {
        $products = $repo->findFiltered(
            $request->query->get('search', ''),
            $request->query->get('category', ''),
            $request->query->get('sort', '')
        );

        return $this->render('html/Product/Client/ProductList.html.twig', [
            'products' => $products,
        ]);
    }

    #[Route('/BuyProduct', name: 'Client_product_Buy', methods: ['POST'])]
    public function buy(
        Request                $request,
        ProductRepository      $productRepo,
        UserRepository         $userRepo,
        EntityManagerInterface $em
    ): Response {

        $user = $this->getUser();
        $clientId     = $user->getId(); // new filter
        $productId = (int) $request->request->get('Productid');

        // ── CSRF ──────────────────────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('BuyProduct_', $request->request->get('_token'))) {
            $this->addFlash('error', 'Requête invalide (CSRF)');
            return $this->redirectToRoute('Client_product_list');
        }

        // ── Validate subscription type ────────────────────────────────────────
        $allowedTypes = ['MONTHLY', 'ANNUALLY', 'TRANSACTION', 'ONE_TIME'];
        $type         = strtoupper($request->request->get('type', ''));

        if (!in_array($type, $allowedTypes, true)) {
            $this->addFlash('error', "Type d'abonnement invalide.");
            return $this->redirectToRoute('Client_product_list');
        }

        // ── Fetch entities ────────────────────────────────────────────────────
        $product = $productRepo->find($productId);
        $user    = $userRepo->find($clientId);

        if (!$product) {
            $this->addFlash('error', 'Produit ou utilisateur introuvable.');
            return $this->redirectToRoute('Client_product_list');
        }

        // ── Expiration date based on type ─────────────────────────────────────
        $expiration = new \DateTime();
        match ($type) {
            'MONTHLY'     => $expiration->modify('+1 month'),
            'ANNUALLY'    => $expiration->modify('+1 year'),
            'TRANSACTION' => $expiration->modify('+7 days'),
            'ONE_TIME'    => $expiration->modify('+1 day'),
        };

        // ── Create subscription ───────────────────────────────────────────────
        $subscription = new ProductSubscription();
        $subscription->setClient($clientId);
        $subscription->setProduct($productId);
        $subscription->setClientUser($user);
        $subscription->setProductObj($product);
        $subscription->setType($type);
        $subscription->setStatus('ACTIVE');
        $subscription->setSubscriptionDate(new \DateTime());
        $subscription->setExpirationDate($expiration);

        $em->persist($subscription);
        $em->flush();

        $this->addFlash('success', 'Abonnement souscrit avec succès ('.$type.').');

        return $this->redirectToRoute('Client_product_list');
    }
}