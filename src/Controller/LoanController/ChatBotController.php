<?php

namespace App\Controller\LoanController;

use App\Service\Loan\ChatBotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\User\UserRepository;

#[Route('/chatbot')]
class ChatBotController extends AbstractController
{
    public function __construct(
        private ChatBotService $chatBotService,
        private UserRepository $userRepository
    ) {}

#[Route('/ask', name: 'chatbot_ask', methods: ['POST'])]
public function ask(Request $request): JsonResponse
{
    $question = trim($request->get('question', ''));
    $loanId = $request->get('loanId') ? (int) $request->get('loanId') : null;
    
    // ✅ Utiliser findOneBy (méthode Doctrine standard)
    $securityUser = $this->getUser();
    $userId = null;
    
    if ($securityUser) {
        $email = $securityUser->getUserIdentifier();
        $fullUser = $this->userRepository->findOneBy(['email' => $email]);
        
        if ($fullUser) {
            $userId = (int) $fullUser->getId();
        }
    }

    if (empty($question)) {
        return $this->json(['error' => 'Question vide'], 400);
    }

    try {
        $result = $this->chatBotService->processQuestion($question, $loanId, $userId);
        
        return $this->json([
            'success' => true,
            'answer' => $result['answer'],
        ]);

    } catch (\Exception $e) {
        return $this->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}
}