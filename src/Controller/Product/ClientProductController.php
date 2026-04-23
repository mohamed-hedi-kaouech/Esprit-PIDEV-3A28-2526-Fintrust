<?php

namespace App\Controller\Product;

use App\Entity\Product\ProductSubscription;
use App\Entity\Wallet\Wallet;
use App\Repository\Product\ProductRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        Request $request,
        ProductRepository $productRepo,
        UserRepository $userRepo,
        EntityManagerInterface $em
    ): Response
    {
        // ── User ─────────────────────────────────────────────
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('Client_product_list', [
                'swal'    => 'error',
                'msg'     => 'Utilisateur non connecté.',
            ]);
        }

        $clientId = $user->getId();

        // ── Product ──────────────────────────────────────────
        $productId = (int)$request->request->get('Productid');
        $product = $productRepo->find($productId);

        if (!$product) {
            return $this->redirectToRoute('Client_product_list', [
                'swal' => 'error',
                'msg'  => 'Produit introuvable.',
            ]);
        }

        // ── Wallet ───────────────────────────────────────────
        $wallet = $em->getRepository(Wallet::class)
            ->findOneBy(['idUser' => $clientId]);

        if (!$wallet) {
            return $this->redirectToRoute('Client_product_list', [
                'swal' => 'error',
                'msg'  => 'Wallet introuvable.',
            ]);
        }

        // ── Balance check ────────────────────────────────────
        if ($wallet->getSolde() < $product->getPrice()) {
            return $this->redirectToRoute('Client_product_list', [
                'swal' => 'error',
                'msg'  => 'Solde insuffisant.',
            ]);
        }

        // ── CSRF ─────────────────────────────────────────────
        if (!$this->isCsrfTokenValid('BuyProduct_', $request->request->get('_token'))) {
            return $this->redirectToRoute('Client_product_list', [
                'swal' => 'error',
                'msg'  => 'Requête invalide (CSRF).',
            ]);
        }

        // ── Type validation ──────────────────────────────────
        $allowedTypes = ['MONTHLY', 'ANNUALLY', 'TRANSACTION', 'ONE_TIME'];
        $type = strtoupper($request->request->get('type', ''));

        if (!in_array($type, $allowedTypes, true)) {
            return $this->redirectToRoute('Client_product_list', [
                'swal' => 'error',
                'msg'  => "Type d'abonnement invalide.",
            ]);
        }

        // ── Expiration date ──────────────────────────────────
        $expiration = new \DateTime();
        match ($type) {
            'MONTHLY'     => $expiration->modify('+1 month'),
            'ANNUALLY'    => $expiration->modify('+1 year'),
            'TRANSACTION' => $expiration->modify('+7 days'),
            'ONE_TIME'    => $expiration->modify('+1 day'),
        };

        // ── Create subscription ──────────────────────────────
        $subscription = new ProductSubscription();
        $subscription->setClient($clientId);
        $subscription->setProduct($productId);
        $subscription->setClientUser($user);
        $subscription->setProductObj($product);
        $subscription->setType($type);
        $subscription->setStatus('ACTIVE');
        $subscription->setSubscriptionDate(new \DateTime());
        $subscription->setExpirationDate($expiration);

        // ── Update wallet ────────────────────────────────────
        $wallet->setSolde($wallet->getSolde() - $product->getPrice());

        $em->persist($wallet);
        $em->persist($subscription);
        $em->flush();

        // ── Webhook 1 (n8n) ─────────────────────────────────
        $this->callWebhook(
            "http://localhost:5680/webhook/775c96dd-935c-455d-a9d4-5cb84ff1ea8a",
            [
                "ProductCategorie" => $product->getCategory(),
                "ProductType"      => $type,
                "Price"            => $product->getPrice(),
            ]
        );

        // ── Webhook 2 (Invoice) ──────────────────────────────
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . rand(100, 999);

        $this->callWebhook(
            "http://localhost:5680/webhook/generate-bankfintrust-invoice",
            [
                "invoiceNumber"      => $invoiceNumber,
                "subscriptionId"     => 1,
                "customerName"       => $user->getNom() . ' ' . $user->getPrenom(),
                "customerEmail"      => $user->getEmail(),
                "productDescription" => $product->getDescription(),
                "productCategory"    => $product->getCategory(),
                "price"              => $product->getPrice(),
                "TVA"                => 19,
            ]
        );

        // ── Success ──────────────────────────────────────────
        return $this->redirectToRoute('Client_product_list', [
            'swal' => 'success',
            'msg'  => 'Abonnement souscrit avec succès (' . $type . ').',
        ]);
    }

    // ── Reusable webhook function ────────────────────────────
    private function callWebhook(string $url, array $data): void
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_TIMEOUT        => 5,
        ]);

        curl_exec($ch);
    }
}