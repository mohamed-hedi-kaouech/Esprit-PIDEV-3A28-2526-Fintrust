<?php

namespace App\Service\Voice;

class VoiceIntentResolverService
{
    public const CHECK_BALANCE = 'CHECK_BALANCE';
    public const LIST_TRANSACTIONS = 'LIST_TRANSACTIONS';
    public const WALLET_STATUS = 'WALLET_STATUS';
    public const LOAN_ADVICE = 'LOAN_ADVICE';
    public const MARKET_INSIGHTS = 'MARKET_INSIGHTS';
    public const TRANSFER_PREVIEW = 'TRANSFER_PREVIEW';
    public const CHEQUE_STATUS = 'CHEQUE_STATUS';
    public const UNKNOWN = 'UNKNOWN';

    public function __construct(
        private readonly VoiceLanguageService $languageService,
    ) {
    }

    /**
     * @return array{intent:string,entities:array<string, mixed>,confidence:float,language:string}
     */
    public function resolve(string $transcript, ?string $language = null): array
    {
        $language = $this->languageService->detectLanguage($transcript, $language);
        $text = $this->languageService->normalizeText($transcript);
        $entities = $this->extractEntities($text);

        $scores = [
            self::CHECK_BALANCE => $this->score($text, ['solde', 'balance', 'xaalis', 'argent disponible', 'combien j ai']),
            self::LIST_TRANSACTIONS => $this->score($text, ['transactions', 'operations', 'dernieres', 'historique', 'recentes', 'history']),
            self::WALLET_STATUS => $this->score($text, ['wallet stable', 'etat du wallet', 'statut wallet', 'operationnel', 'stable', 'bloque', 'status']),
            self::LOAN_ADVICE => $this->score($text, ['pret', 'credit', 'loan', 'emprunt', 'demander un pret', 'boroom']),
            self::MARKET_INSIGHTS => $this->score($text, ['marche', 'market', 'prediction', 'change', 'usd', 'eur', 'bitcoin', 'gold', 'or']),
            self::TRANSFER_PREVIEW => $this->score($text, ['envoyer', 'transferer', 'virement', 'send', 'transfer', 'yonnee']) + ($entities['amount'] !== null ? 0.24 : 0.0),
            self::CHEQUE_STATUS => $this->score($text, ['cheque', 'chequier', 'check', 'signature', 'beneficiaire']),
        ];

        arsort($scores);
        $intent = (string) array_key_first($scores);
        $confidence = round((float) reset($scores), 2);

        if ($confidence < 0.22) {
            $intent = self::UNKNOWN;
            $confidence = 0.15;
        }

        return [
            'intent' => $intent,
            'entities' => array_filter($entities, static fn (mixed $value): bool => $value !== null && $value !== ''),
            'confidence' => min(0.96, $confidence),
            'language' => $language,
        ];
    }

    /**
     * @param string[] $keywords
     */
    private function score(string $text, array $keywords): float
    {
        $score = 0.0;

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $score += str_contains($keyword, ' ') ? 0.42 : 0.28;
            }
        }

        return min(0.92, $score);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEntities(string $text): array
    {
        $amount = null;
        if (preg_match('/(\d+(?:[,.]\d{1,2})?)\s*(tnd|dt|dinars?|eur|euro?s?|usd|dollars?)?/i', $text, $matches)) {
            $amount = (float) str_replace(',', '.', $matches[1]);
        }

        $currency = null;
        if (preg_match('/\b(tnd|dt|dinars?|eur|euro?s?|usd|dollars?)\b/i', $text, $matches)) {
            $currency = match (true) {
                str_starts_with($matches[1], 'eur') => 'EUR',
                str_starts_with($matches[1], 'usd') || str_starts_with($matches[1], 'dollar') => 'USD',
                default => 'TND',
            };
        }

        $chequeNumber = null;
        if (preg_match('/\b(chq[0-9a-z-]+|cheque\s*[#:]?\s*([0-9a-z-]+))\b/i', $text, $matches)) {
            $chequeNumber = strtoupper(str_replace(' ', '', $matches[1]));
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'cheque_number' => $chequeNumber,
        ];
    }
}
