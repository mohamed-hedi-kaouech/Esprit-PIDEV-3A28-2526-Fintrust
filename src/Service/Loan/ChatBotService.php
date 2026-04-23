<?php

namespace App\Service\Loan;

use App\Entity\Loan\Loan;
use App\Entity\Loan\Repayment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatBotService
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const MODEL = 'anthropic/claude-3.5-haiku';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $openRouterKey,
        private LoanService $loanService,
    ) {}

    // ================================
    // MAIN ENTRY
    // ================================
    public function processQuestion(string $question, ?int $loanId = null, ?int $userId = null): array
    {
        $intent = $this->detectIntent($question);
        $contextLevel = $this->determineContextLevel($intent, $loanId);

        return match ($contextLevel) {
            'LOAN_CONTEXT' => $this->handleLoanContext($question, $loanId, $intent),
            'USER_CONTEXT' => $this->handleUserContext($question, $userId, $intent),
            'PAYMENT_CONTEXT' => $this->handlePaymentContext($question, $loanId),
            default => $this->askGeneral($question, $intent),
        };
    }

    // ================================
    // INTENT DETECTION (IMPROVED)
    // ================================
    private function detectIntent(string $question): string
    {
        $q = strtolower($question);

        $map = [
            'LOAN_COUNT' => ['combien de prêts', 'nombre de prêts', 'how many loans'],
            'PAYMENT_AMOUNT' => ['payer', 'mensualité', 'échéance'],
            'REMAINING_BALANCE' => ['reste', 'solde', 'capital restant'],
            'NEXT_PAYMENT' => ['prochaine', 'quand payer', 'date'],
            'PAYMENT_METHOD' => ['comment payer', 'carte', 'wallet'],
            'GENERAL_HELP' => ['aide', 'help'],
        ];

        foreach ($map as $intent => $keywords) {
            foreach ($keywords as $word) {
                if (str_contains($q, $word)) {
                    return $intent;
                }
            }
        }

        return 'GENERAL_HELP';
    }

    // ================================
    // CONTEXT DECISION
    // ================================
    private function determineContextLevel(string $intent, ?int $loanId): string
    {
        if ($intent === 'LOAN_COUNT') {
            return 'USER_CONTEXT';
        }

        if ($loanId) {
            return 'LOAN_CONTEXT';
        }

        return 'NO_CONTEXT';
    }

    // ================================
    // HANDLERS
    // ================================
    private function handleLoanContext(string $question, ?int $loanId, string $intent): array
    {
        $loan = $this->loanService->getLoanById($loanId);

        if (!$loan) {
            return $this->error("Prêt non trouvé.");
        }

        $data = $this->buildLoanData($loan);
        $prompt = $this->buildPrompt($data, $intent);

        return $this->callAI($question, $prompt, true);
    }

    private function handleUserContext(string $question, ?int $userId, string $intent): array
    {
        $loans = $this->loanService->getLoansByUserId($userId);
        $data = $this->buildUserData($loans);

        $prompt = $this->buildPrompt($data, $intent);

        return $this->callAI($question, $prompt, true);
    }

    private function handlePaymentContext(string $question, ?int $loanId): array
    {
        return $this->handleLoanContext($question, $loanId, 'PAYMENT_AMOUNT');
    }

    private function askGeneral(string $question, string $intent): array
    {
        return $this->callAI($question, $this->basePrompt($intent));
    }

    // ================================
    // DATA BUILDERS
    // ================================
    private function buildLoanData(Loan $loan): string
    {
        $monthly = $this->loanService->calculateMonthlyPayment($loan);
        $stats = $this->loanService->getLoanStats($loan);
        $next = $this->loanService->getNextUnpaidRepayment($loan);

        return "
=== DONNÉES PRÊT ===
Prêt #{$loan->getLoanId()}
Type: {$loan->getLoanType()}
Mensualité: " . number_format($monthly, 3) . " TND
Capital restant: {$loan->getRemainingPrincipal()} TND
Progression: {$stats['progress']}%

Prochaine échéance:
Montant: {$next?->getMonthlyPayment()} TND
";
    }

    private function buildUserData(array $loans): string
    {
        $count = count($loans);
        $active = count(array_filter($loans, fn($l) => $l->getStatus() === 'ACTIVE'));

        return "
=== CLIENT ===
Nombre de prêts: $count
Prêts actifs: $active
";
    }

    // ================================
    // PROMPT (VERY IMPORTANT)
    // ================================
    private function buildPrompt(string $data, string $intent): string
    {
        return $this->basePrompt($intent) . "\n\nINTENTION: $intent\n" . $data;
    }

    private function basePrompt(string $intent): string
    {
        return <<<PROMPT
Tu es Fintrust Assistant, expert en prêts.

RÈGLES:
- Réponds en français
- Utilise uniquement les données fournies
- Sois clair et utile
- Ne jamais inventer

COMPORTEMENT:
- Si montant → donne chiffre exact
- Si date → précise délai
- Si info manquante → dis-le

PROMPT;
    }

    // ================================
    // AI CALL
    // ================================
    private function callAI(string $question, string $systemPrompt, bool $contextUsed = false): array
    {
        $response = $this->httpClient->request('POST', self::OPENROUTER_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openRouterKey,
            ],
            'json' => [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $question],
                ],
                'temperature' => 0.2,
            ],
        ]);

        $data = $response->toArray();

        return [
            'answer' => $data['choices'][0]['message']['content'] ?? 'Erreur',
            'context_used' => $contextUsed,
        ];
    }

    private function error(string $msg): array
    {
        return [
            'answer' => $msg,
            'error' => true
        ];
    }
}