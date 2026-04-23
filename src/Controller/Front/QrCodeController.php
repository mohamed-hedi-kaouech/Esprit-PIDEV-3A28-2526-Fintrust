<?php

namespace App\Controller\Front;

use App\Service\QrCodeService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/espace-client', name: 'front_')]
class QrCodeController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly QrCodeService $qrCodeService,
    ) {}

    #[Route('/qr-code/{token}', name: 'qr_code_image', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function image(string $token, Request $request): Response
    {
        $user = $this->userService->findByQrToken($token);

        if (!$user) {
            throw $this->createNotFoundException('QR code invalide ou expire.');
        }

        return new Response(
            $this->qrCodeService->getQrSvg($token, $request->getSchemeAndHttpHost()),
            Response::HTTP_OK,
            [
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }
}
