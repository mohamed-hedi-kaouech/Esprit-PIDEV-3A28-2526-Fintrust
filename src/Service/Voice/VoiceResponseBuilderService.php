<?php

namespace App\Service\Voice;

use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Wallet;
use App\Service\WalletAssistantService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class VoiceResponseBuilderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletAssistantService $walletAssistantService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $entities
     * @return array{action:string,response_text:string,details:array<string, mixed>,audio_response:array<string, mixed>|null,suggestions:array<int, string>,redirect_url:string|null}
     */
    public function build(Wallet $wallet, string $intent, array $entities, string $language): array
    {
        return match ($intent) {
            VoiceIntentResolverService::CHECK_BALANCE => $this->fromAssistant($wallet, 'balance', 'wallet.balance.read', $language),
            VoiceIntentResolverService::LIST_TRANSACTIONS => $this->fromAssistant($wallet, 'transactions', 'wallet.transactions.list', $language),
            VoiceIntentResolverService::OPEN_TRANSACTION_FORM => $this->buildNavigationResponse(
                'wallet.transaction.open_form',
                'Je vous dirige vers la page de creation de transaction.',
                'front_wallet_transaction_new',
                $language,
                ['Vous pourrez ensuite choisir depot ou retrait et confirmer votre operation.']
            ),
            VoiceIntentResolverService::WALLET_STATUS => $this->fromAssistant($wallet, 'status', 'wallet.status.read', $language),
            VoiceIntentResolverService::LOAN_ADVICE => $this->fromAssistant($wallet, 'loan', 'wallet.loan.advice', $language),
            VoiceIntentResolverService::MARKET_INSIGHTS => $this->fromAssistant($wallet, 'market', 'wallet.market.insights', $language),
            VoiceIntentResolverService::TRANSFER_PREVIEW => $this->buildTransferPreview($wallet, $entities, $language),
            VoiceIntentResolverService::CHEQUE_STATUS => $this->buildChequeStatus($wallet, $entities, $language),
            default => $this->buildFallback($language),
        };
    }

    /**
     * @return array{action:string,response_text:string,details:array<string, mixed>,audio_response:array<string, mixed>|null,suggestions:array<int, string>,redirect_url:string|null}
     */
    private function fromAssistant(Wallet $wallet, string $assistantIntent, string $action, string $language): array
    {
        $answer = $this->walletAssistantService->answer($wallet, $assistantIntent);
        $details = is_array($answer['details'] ?? null) ? $answer['details'] : [];
        $text = (string) ($answer['message'] ?? 'Reponse wallet indisponible.');

        return [
            'action' => $action,
            'response_text' => $this->translateIfNeeded($text, $language),
            'details' => [
                'title' => (string) ($answer['title'] ?? 'Assistant vocal'),
                'items' => $details,
                'assistant_payload' => $answer,
            ],
            'audio_response' => $this->buildAudioPlaceholder($text, $language),
            'suggestions' => $this->buildSuggestions($language),
            'redirect_url' => null,
        ];
    }

    /**
     * @param array<string, mixed> $entities
     * @return array{action:string,response_text:string,details:array<string, mixed>,audio_response:array<string, mixed>|null,suggestions:array<int, string>,redirect_url:string|null}
     */
    private function buildTransferPreview(Wallet $wallet, array $entities, string $language): array
    {
        $amount = isset($entities['amount']) ? (float) $entities['amount'] : 0.0;
        $currency = (string) ($entities['currency'] ?? $wallet->getDevise());
        $balance = (float) $wallet->getSolde();

        if ($amount <= 0.0) {
            $text = 'Je peux preparer un apercu de transfert. Indiquez simplement le montant, par exemple: envoyer 50 dinars.';
        } elseif ($wallet->getEstBloque() || !$wallet->getEstActif()) {
            $text = 'Apercu uniquement: votre wallet est bloque ou inactif, aucun transfert ne peut etre execute maintenant.';
        } elseif ($amount > $balance) {
            $text = sprintf('Apercu transfert: %.2f %s depasse votre solde disponible de %.2f %s. Aucun transfert n est lance.', $amount, $currency, $balance, $wallet->getDevise());
        } else {
            $remaining = $balance - $amount;
            $text = sprintf('Apercu transfert: envoyer %.2f %s est possible. Solde estime apres operation: %.2f %s. Ceci est une simulation, rien n est execute.', $amount, $currency, $remaining, $wallet->getDevise());
        }

        return [
            'action' => 'wallet.transfer.preview',
            'response_text' => $this->translateIfNeeded($text, $language),
            'details' => [
                'amount' => $amount,
                'currency' => $currency,
                'current_balance' => $balance,
                'simulated' => true,
            ],
            'audio_response' => $this->buildAudioPlaceholder($text, $language),
            'suggestions' => $this->buildSuggestions($language),
            'redirect_url' => null,
        ];
    }

    /**
     * @param array<string, mixed> $entities
     * @return array{action:string,response_text:string,details:array<string, mixed>,audio_response:array<string, mixed>|null,suggestions:array<int, string>,redirect_url:string|null}
     */
    private function buildChequeStatus(Wallet $wallet, array $entities, string $language): array
    {
        $qb = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->andWhere('c.wallet = :wallet')
            ->setParameter('wallet', $wallet)
            ->orderBy('c.dateEmission', 'DESC')
            ->setMaxResults(5);

        if (isset($entities['cheque_number'])) {
            $qb
                ->andWhere('UPPER(c.numeroCheque) = :number')
                ->setParameter('number', (string) $entities['cheque_number']);
        }

        /** @var Cheque[] $cheques */
        $cheques = $qb->getQuery()->getResult();

        if ($cheques === []) {
            $text = 'Aucun cheque recent n est trouve pour votre wallet.';
        } else {
            $latest = $cheques[0];
            $text = sprintf(
                'Votre dernier cheque %s est au statut %s pour %.2f %s.',
                $latest->getNumeroCheque(),
                $latest->getStatut(),
                $latest->getMontant(),
                $wallet->getDevise()
            );
        }

        return [
            'action' => 'wallet.cheque.status',
            'response_text' => $this->translateIfNeeded($text, $language),
            'details' => [
                'cheques' => array_map(static fn (Cheque $cheque): array => [
                    'number' => $cheque->getNumeroCheque(),
                    'status' => $cheque->getStatut(),
                    'amount' => $cheque->getMontant(),
                    'beneficiary' => $cheque->getBeneficiaire(),
                    'issued_at' => $cheque->getDateEmission()->format(\DateTimeInterface::ATOM),
                ], $cheques),
            ],
            'audio_response' => $this->buildAudioPlaceholder($text, $language),
            'suggestions' => $this->buildSuggestions($language),
            'redirect_url' => null,
        ];
    }

    /**
     * @return array{action:string,response_text:string,details:array<string, mixed>,audio_response:array<string, mixed>|null,suggestions:array<int, string>,redirect_url:string|null}
     */
    private function buildFallback(string $language): array
    {
        $text = 'Je peux vous aider sur le solde, les transactions, le statut wallet, le pret, le marche, un apercu de transfert ou un cheque.';

        return [
            'action' => 'voice.intent.unsupported',
            'response_text' => $this->translateIfNeeded($text, $language),
            'details' => ['supported_intents' => $this->buildSuggestions($language)],
            'audio_response' => $this->buildAudioPlaceholder($text, $language),
            'suggestions' => $this->buildSuggestions($language),
            'redirect_url' => null,
        ];
    }

    /**
     * @param list<string> $details
     *
     * @return array{action:string,response_text:string,details:array<string, mixed>,audio_response:array<string, mixed>|null,suggestions:array<int, string>,redirect_url:string|null}
     */
    private function buildNavigationResponse(
        string $action,
        string $text,
        string $routeName,
        string $language,
        array $details = [],
    ): array {
        return [
            'action' => $action,
            'response_text' => $this->translateIfNeeded($text, $language),
            'details' => [
                'title' => 'Navigation assistee',
                'items' => $details,
            ],
            'audio_response' => $this->buildAudioPlaceholder($text, $language),
            'suggestions' => $this->buildSuggestions($language),
            'redirect_url' => $this->urlGenerator->generate($routeName),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAudioPlaceholder(string $text, string $language): array
    {
        return [
            'mode' => 'placeholder',
            'mime_type' => 'text/plain',
            'text' => $this->translateIfNeeded($text, $language),
            'hint' => 'Le front peut lire ce texte avec Web Speech API si disponible.',
        ];
    }

    /**
     * @return string[]
     */
    private function buildSuggestions(string $language): array
    {
        return match ($language) {
            'en' => ['Show my balance', 'Show latest transactions', 'Can I request a loan?', 'Market insights'],
            'wo' => ['Sama solde', 'Sama transactions', 'Wallet bi naka la?', 'Marse bi naka la?'],
            default => ['Voir mon solde', 'Montre mes dernieres transactions', 'Puis-je demander un pret ?', 'Quel est l etat du marche ?'],
        };
    }

    private function translateIfNeeded(string $text, string $language): string
    {
        if ($language === 'en') {
            return '[EN simulated] ' . $text;
        }

        if ($language === 'wo') {
            return '[WO simulation] ' . $text;
        }

        return $text;
    }
}
