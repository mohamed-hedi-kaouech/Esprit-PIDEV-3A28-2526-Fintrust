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

    /**
     * Main entry point - determines context level automatically
     */
    public function processQuestion(string $question, ?int $loanId = null, ?int $userId = null): array
    {
        // 1. Detect question intent
        $intent = $this->detectIntent($question);
        
        // 2. Determine required context level
        $contextLevel = $this->determineContextLevel($intent, $loanId);
        
        // 3. Build appropriate response based on context level
        switch ($contextLevel) {
            case 'NO_CONTEXT':
                // General FAQ - no loan needed
                return $this->askGeneral($question, $intent);
                
            case 'LOAN_CONTEXT':
                // Needs specific loan data
                $loan = $this->loanService->getLoanById($loanId);
                if (!$loan) {
                    return $this->createErrorResponse('Prêt non trouvé');
                }
                return $this->askWithLoanContext($question, $loan, $intent);
                
            case 'USER_CONTEXT':
                // Needs all user loans overview
                $loans = $this->loanService->getLoansByUserId($userId);
                return $this->askWithUserContext($question, $loans, $intent);
                
            case 'PAYMENT_CONTEXT':
                // Needs payment/wallet specific logic
                $loan = $loanId ? $this->loanService->getLoanById($loanId) : null;
                return $this->askPaymentRelated($question, $loan, $intent);
                
            default:
                return $this->askGeneral($question, $intent);
        }
    }

    /**
     * Detect user intent from question
     */
    private function detectIntent(string $question): string
    {
        $question = strtolower($question);
        
        $intents = [
            'LOAN_COUNT' => ['combien de prêts', 'nombre de prêts', 'how many loans', 'mes prêts combien'],
            'LOAN_AMOUNT' => ['combien', 'montant', 'coût total', 'intérêt total', 'capital initial'],
            'PAYMENT_AMOUNT' => ['mensualité', 'payer', 'paiement', 'échéance', 'combien ce mois', 'montant'],
            'REMAINING_BALANCE' => ['reste', 'capital restant', 'combien reste', 'solde', 'restant'],
            'LOAN_STATUS' => ['statut', 'actif', 'en attente', 'approuvé', 'état'],
            'PAYMENT_METHOD' => ['comment payer', 'wallet', 'carte', 'mode de paiement', 'méthode'],
            'NEXT_PAYMENT' => ['prochain', 'prochaine', 'mois prochain', 'quand payer', 'date'],
            'LOAN_DETAILS' => ['détails', 'info', 'informations', 'mon prêt', 'caractéristiques'],
            'PAYMENT_HISTORY' => ['historique', 'déjà payé', 'mensualités payées', 'progression'],
            'EARLY_PAYMENT' => ['remboursement anticipé', 'payer avant', ' solder', 'terminer avant'],
            'SIMULATION' => ['simuler', 'calculer', 'estimer', 'combien pour'],
            'GENERAL_HELP' => ['aide', 'help', 'comment ça marche', 'expliquer'],
        ];
        
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($question, $keyword)) {
                    return $intent;
                }
            }
        }
        
        return 'GENERAL_HELP';
    }

    /**
     * Determine how much context we need
     */
    private function determineContextLevel(string $intent, ?int $loanId): string
    {
        $loanRequiredIntents = [
            'PAYMENT_AMOUNT', 'REMAINING_BALANCE', 'LOAN_STATUS', 'LOAN_COUNT',
            'NEXT_PAYMENT', 'LOAN_DETAILS', 'PAYMENT_HISTORY', 'EARLY_PAYMENT'
        ];
        
        $paymentIntents = ['PAYMENT_AMOUNT', 'PAYMENT_METHOD', 'NEXT_PAYMENT'];
        
        if (in_array($intent, $paymentIntents) && !$loanId) {
            return 'USER_CONTEXT'; // Show all loans payment overview
        }
        
        if (in_array($intent, $loanRequiredIntents)) {
            return $loanId ? 'LOAN_CONTEXT' : 'USER_CONTEXT';
        }
        
        return 'NO_CONTEXT';
    }

    /**
     * General FAQ - no personal data needed
     */
    private function askGeneral(string $question, string $intent): array
    {
        $systemPrompt = $this->buildGeneralPrompt($intent);
        return $this->callAPI($question, $systemPrompt);
    }

    /**
     * Specific loan context - detailed personal data
     */
    private function askWithLoanContext(string $question, Loan $loan, string $intent): array
    {
        $contextData = $this->buildLoanContextData($loan);
        $systemPrompt = $this->buildPersonalizedPrompt($contextData, $intent);
        
        $result = $this->callAPI($question, $systemPrompt);
        $result['context_used'] = true;
        $result['loan_id'] = $loan->getLoanId();
        
        return $result;
    }

    /**
     * User overview - multiple loans summary
     */
    private function askWithUserContext(string $question, array $loans, string $intent): array
    {
        $contextData = $this->buildUserContextData($loans);
        $systemPrompt = $this->buildUserOverviewPrompt($contextData);
        
        return $this->callAPI($question, $systemPrompt);
    }

    /**
     * Payment-specific logic with wallet integration hints
     */
    private function askPaymentRelated(string $question, ?Loan $loan, string $intent): array
    {
        $paymentContext = [];
        
        if ($loan) {
            $nextUnpaid = $this->loanService->getNextUnpaidRepayment($loan);
            $paymentContext = [
                'next_payment_amount' => $nextUnpaid?->getMonthlyPayment(),
                'next_payment_month' => $nextUnpaid?->getMonth(),
                'payment_status' => $nextUnpaid?->getStatus(),
                'wallet_suggestion' => 'Utilisez le bouton PAYER sur votre tableau de remboursement',
            ];
        }
        
        $systemPrompt = $this->buildPaymentPrompt($paymentContext);
        return $this->callAPI($question, $systemPrompt);
    }

    /**
     * Build rich loan context data
     */
    private function buildLoanContextData(Loan $loan): array
    {
        $monthlyPayment = $this->loanService->calculateMonthlyPayment($loan);
        $totalInterest = $this->loanService->calculateTotalInterest($loan);
        $stats = $this->loanService->getLoanStats($loan);
        $nextUnpaid = $this->loanService->getNextUnpaidRepayment($loan);
        $repayments = $loan->getRepayments();
        
        // Calculate days until next payment
        $daysUntilPayment = $this->calculateDaysUntilPayment($nextUnpaid);
        
        return [
            'loan_id' => $loan->getLoanId(),
            'type' => $loan->getLoanType(),
            'amount' => $loan->getAmount(),
            'duration' => $loan->getDuration(),
            'interest_rate' => $loan->getInterestRate(),
            'monthly_payment' => number_format($monthlyPayment, 3),
            'total_interest' => number_format($totalInterest, 3),
            'remaining_principal' => $loan->getRemainingPrincipal(),
            'status' => $loan->getStatus(),
            'progress_percent' => $stats['progress'],
            'paid_count' => $stats['paidCount'],
            'unpaid_count' => $stats['unpaidCount'],
            'total_paid_amount' => number_format($stats['totalPaid'], 3),
            'total_remaining_amount' => number_format($stats['totalUnpaid'], 3),
            'next_payment' => $nextUnpaid ? [
                'month' => $nextUnpaid->getMonth(),
                'amount' => $nextUnpaid->getMonthlyPayment(),
                'capital' => $nextUnpaid->getCapitalPart(),
                'interest' => $nextUnpaid->getInterestPart(),
                'due_date' => $this->estimateDueDate($nextUnpaid->getMonth()),
                'days_until' => $daysUntilPayment,
            ] : null,
            'completion_date' => $this->estimateCompletionDate($loan, $stats['unpaidCount']),
        ];
    }

    /**
     * Build user overview for multiple loans
     */
    private function buildUserContextData(array $loans): array
    {
        $overview = [];
        $totalMonthly = 0;
        $totalRemaining = 0;
        
        foreach ($loans as $loan) {
            $monthly = $this->loanService->calculateMonthlyPayment($loan);
            $stats = $this->loanService->getLoanStats($loan);
            
            $overview[] = [
                'id' => $loan->getLoanId(),
                'type' => $loan->getLoanType(),
                'status' => $loan->getStatus(),
                'monthly' => number_format($monthly, 3),
                'progress' => $stats['progress'],
                'remaining' => $stats['unpaidCount'],
            ];
            
            if ($loan->getStatus() === 'ACTIVE') {
                $totalMonthly += $monthly;
                $totalRemaining += $stats['totalUnpaid'];
            }
        }
        
        return [
            'loan_count' => count($loans),
            'active_loans' => count(array_filter($loans, fn($l) => $l->getStatus() === 'ACTIVE')),
            'total_monthly_payment' => number_format($totalMonthly, 3),
            'total_remaining_all_loans' => number_format($totalRemaining, 3),
            'loans' => $overview,
        ];
    }

    /**
     * Build system prompt with general knowledge
     */
    private function buildGeneralPrompt(string $intent): string
    {
        $base = <<<BASE
Tu es "Fintrust Assistant", conseiller bancaire virtuel professionnel.

RÈGLES STRICTES:
- Réponds UNIQUEMENT en français
- Sois concis (2-3 phrases max)
- Ne invente jamais d'informations
- Si tu ne sais pas, dis: "Je vous mets en relation avec un conseiller au +216 71 123 456"
BASE;

        $specifics = [
            'SIMULATION' => <<<SIM
\n\nINFO SIMULATION:
• Formulaire disponible sur /loan/simulator
• Saisir: montant, durée, type
• Résultat instantané avec tableau prévisionnel
SIM,
            'PAYMENT_METHOD' => <<<PAY
\n\nMODES DE PAIEMENT:
• Wallet: déduction automatique mensuelle
• Carte bancaire: paiement ponctuel
• Virement: IBAN disponible sur demande
• Report: possible avec frais 2% (max 2 fois)
PAY,
            'GENERAL_HELP' => <<<HELP
\n\nCONTACTS:
• Support: support@fintrust.tn
• Tél: +216 71 123 456
• Horaires: Lun-Ven 8h-18h
HELP,
        ];

        return $base . ($specifics[$intent] ?? '');
    }

    /**
     * Build personalized prompt with loan data
     */
    private function buildPersonalizedPrompt(array $data, string $intent): string
    {
        $base = $this->buildGeneralPrompt($intent);
        $totalPayments = $data['paid_count'] + $data['unpaid_count'];
        
        $personal = <<<PERS

\n\n=== DONNÉES CLIENT (UTILISER EXACTEMENT) ===
• Prêt #{$data['loan_id']} - {$data['type']}
• Montant initial: {$data['amount']} TND
• Mensualité fixe: {$data['monthly_payment']} TND
• Durée: {$data['duration']} mois | Taux: {$data['interest_rate']}%
• Progression: {$data['progress_percent']}% ({$data['paid_count']}/{$totalPayments} payés)
• Capital restant: {$data['remaining_principal']} TND
• Total intérêts: {$data['total_interest']} TND

PERS;

        if ($data['next_payment']) {
            $np = $data['next_payment'];
            $personal .= <<<NEXT
\nPROCHAIN PAIEMENT:
• Échéance #{$np['month']}: {$np['amount']} TND
• Détail: Capital {$np['capital']} + Intérêts {$np['interest']}
• Date estimée: {$np['due_date']} (dans {$np['days_until']} jours)
• Action: Cliquez "PAYER" sur votre tableau

NEXT;
        }

        $personal .= "\n=== INSTRUCTION ===\nBase tes réponses UNIQUEMENT sur ces données. Ne demande pas au client de vérifier ailleurs.";

        return $base . $personal;
    }

    /**
     * Build user overview prompt
     */
    private function buildUserOverviewPrompt(array $data): string
    {
        $base = $this->buildGeneralPrompt('GENERAL_HELP');
        
        $overview = <<<OV

\n\n=== VUE D'ENSEMBLE CLIENT ===
• Nombre de prêts: {$data['loan_count']}
• Prêts actifs: {$data['active_loans']}
• Total mensualités/mois: {$data['total_monthly_payment']} TND
• Total restant à rembourser: {$data['total_remaining_all_loans']} TND

DÉTAIL PAR PRÊT:
OV;

        foreach ($data['loans'] as $loan) {
            $overview .= "\n• Prêt #{$loan['id']} ({$loan['type']}): {$loan['monthly']}/mois, {$loan['progress']}% payé, {$loan['remaining']} échéances restantes";
        }

        $overview .= "\n\n=== INSTRUCTION ===\nGuide le client vers le prêt approprié. Suggère de cliquer 'Voir détails' pour plus d'informations spécifiques.";

        return $base . $overview;
    }

    /**
     * Build payment-specific prompt
     */
    private function buildPaymentPrompt(array $context): string
    {
        $base = $this->buildGeneralPrompt('PAYMENT_METHOD');
        
        if (empty($context)) {
            return $base . "\n\nAucun prêt spécifique sélectionné. Demandez au client de préciser quel prêt.";
        }
        
        $payment = <<<PAY

\n\n=== PAIEMENT SÉLECTIONNÉ ===
• Prochaine échéance: #{$context['next_payment_month']}
• Montant: {$context['next_payment_amount']} TND
• Statut: {$context['payment_status']}

ACTION SUGGÉRÉE:
{$context['wallet_suggestion']}

PAY;

        return $base . $payment;
    }

    /**
     * Call OpenRouter API
     */
    private function callAPI(string $question, string $systemPrompt): array
    {
        $response = $this->httpClient->request('POST', self::OPENROUTER_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openRouterKey,
                'HTTP-Referer' => 'https://fintrust.local',
                'X-Title' => 'Fintrust Loan Assistant',
            ],
            'json' => [
                'model' => self::MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $question],
                ],
                'temperature' => 0.1, // Very precise for financial data
                'max_tokens' => 400,
            ],
        ]);

        $data = $response->toArray();

        return [
            'answer' => $data['choices'][0]['message']['content'] ?? 'Erreur de réponse',
            'model' => $data['model'] ?? self::MODEL,
            'context_used' => false,
        ];
    }

    /**
     * Create error response
     */
    private function createErrorResponse(string $message): array
    {
        return [
            'answer' => "Désolé, $message. Veuillez vérifier votre sélection ou contacter le support.",
            'model' => 'error',
            'context_used' => false,
            'error' => true,
        ];
    }
                    
    // ======================
    // HELPER METHODS
    // ======================

    private function calculateDaysUntilPayment(?Repayment $repayment): int
    {
        if (!$repayment) return 0;
        
        // Estimate: payment due at month start
        $today = new \DateTime();
        $dueDay = 5; // Assuming payment due on 5th of each month
        $currentDay = (int) $today->format('d');
        
        if ($currentDay <= $dueDay) {
            return $dueDay - $currentDay;
        }
        
        // Next month
        $nextMonth = (clone $today)->modify('first day of next month')->setDate(
            (int) $today->format('Y'),
            (int) $today->format('m') + 1,
            $dueDay
        );
        
        return (int) $today->diff($nextMonth)->days;
    }

    private function estimateDueDate(int $monthNumber): string
    {
        $today = new \DateTime();
        $targetMonth = (clone $today)->modify("+$monthNumber months");
        return $targetMonth->format('d/m/Y');
    }

    private function estimateCompletionDate(Loan $loan, int $remainingMonths): string
    {
        $today = new \DateTime();
        $endDate = (clone $today)->modify("+$remainingMonths months");
        return $endDate->format('m/Y');
    }
}