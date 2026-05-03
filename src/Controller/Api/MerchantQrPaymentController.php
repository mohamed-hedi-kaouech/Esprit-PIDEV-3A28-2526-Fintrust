<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Exception\WalletTransferException;
use App\Service\MerchantQrPaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/v1/merchant/qr', name: 'api_v1_merchant_qr_')]
class MerchantQrPaymentController extends AbstractController
{
    public function __construct(
        private readonly MerchantQrPaymentService $merchantQrPaymentService,
    ) {
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->merchantQrPaymentService->preview(
            $this->getAuthenticatedUser(),
            $this->decodePayload($request)
        ));
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        return $this->json($this->merchantQrPaymentService->generatePayload($this->decodePayload($request)));
    }

    #[Route('/pay', name: 'pay', methods: ['POST'])]
    public function pay(Request $request): JsonResponse
    {
        try {
            return $this->json($this->merchantQrPaymentService->pay(
                $this->getAuthenticatedUser(),
                $this->decodePayload($request)
            ));
        } catch (WalletTransferException $exception) {
            return $this->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        return $payload;
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
