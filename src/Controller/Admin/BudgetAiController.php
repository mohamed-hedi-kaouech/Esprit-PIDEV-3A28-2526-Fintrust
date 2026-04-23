<?php
namespace App\Controller\Admin;
use App\Service\BudgetAiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/admin/budget/ai', name: 'admin_budget_ai_')]
class BudgetAiController extends AbstractController
{
    public function __construct(private BudgetAiService $aiService) {}
    #[Route('', name: 'chat', methods: ['GET'])]
    public function chat(): Response
    {
        return $this->render('admin/budget_ai/chat.html.twig');
    }
    #[Route('/ask', name: 'ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $question = trim($data['question'] ?? '');
        if (empty($question)) {
            return $this->json(['error' => 'Question vide'], 400);
        }
        if (mb_strlen($question) > 500) {
            return $this->json(['error' => 'Question trop longue'], 400);
        }
        $answer = $this->aiService->answer($question);
        return $this->json(['answer' => $answer]);
    }
}