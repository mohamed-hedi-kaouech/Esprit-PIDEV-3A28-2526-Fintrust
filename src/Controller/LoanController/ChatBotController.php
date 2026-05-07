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
        // ✅ 1. CLEAN INPUT (POST only)
        $question = trim((string) $request->request->get('question', ''));
        $loanId = $request->request->get('loanId')
            ? (int) $request->request->get('loanId')
            : null;

        if (!$question) {
            return $this->json([
                'success' => false,
                'answer' => "Veuillez saisir une question."
            ], 400);
        }

        // ✅ 2. NORMALIZE QUESTION (important)
        $question = $this->normalizeQuestion($question);

        // ✅ 3. GET USER ID (cleaner)
        $userId = null;
        if ($this->getUser()) {
            $email = $this->getUser()->getUserIdentifier();
            $user = $this->userRepository->findOneBy(['email' => $email]);
            $userId = $user?->getId();
        }

        // ✅ 4. HANDLE AMBIGUOUS QUESTIONS
        if (!$loanId && $this->isLoanSpecificQuestion($question)) {
            return $this->json([
                'success' => true,
                'answer' => "Veuillez sélectionner un prêt pour répondre précisément."
            ]);
        }

        try {
            $result = $this->chatBotService->processQuestion(
                $question,
                $loanId,
                $userId
            );

            // ✅ 5. SAFETY FALLBACK
            $answer = $result['answer'] ?? '';

            if (!$answer || strlen($answer) < 5) {
                $answer = "Je n'ai pas compris votre demande. Pouvez-vous reformuler ?";
            }

            return $this->json([
                'success' => true,
                'answer' => $answer,
                'context_used' => $result['context_used'] ?? false,
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'answer' => "Erreur serveur. Veuillez réessayer plus tard."
            ], 500);
        }
    }

    // ================================
    // HELPERS
    // ================================

    private function normalizeQuestion(string $q): string
    {
        $q = strtolower(trim($q));
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;

        // normalize common phrases
        $q = str_replace([
            'combien je dois payer',
            'je dois payer combien',
            'paiement combien'
        ], 'paiement', $q);

        return $q;
    }

    private function isLoanSpecificQuestion(string $q): bool
    {
        $keywords = [
            'payer',
            'mensualité',
            'échéance',
            'reste',
            'capital',
            'remboursement'
        ];

        foreach ($keywords as $word) {
            if (str_contains($q, $word)) {
                return true;
            }
        }

        return false;
    }
}
