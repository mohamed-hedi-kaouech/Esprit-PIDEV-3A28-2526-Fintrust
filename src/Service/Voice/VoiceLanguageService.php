<?php

namespace App\Service\Voice;

class VoiceLanguageService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSupportedLanguages(): array
    {
        return [
            'fr' => [
                'code' => 'fr',
                'label' => 'Francais',
                'speech_to_text' => 'simulated',
                'text_to_speech' => 'browser_or_placeholder',
                'intent_detection' => 'keyword_mvp',
            ],
            'en' => [
                'code' => 'en',
                'label' => 'English',
                'speech_to_text' => 'simulated',
                'text_to_speech' => 'browser_or_placeholder',
                'intent_detection' => 'keyword_mvp',
            ],
            'wo' => [
                'code' => 'wo',
                'label' => 'Wolof',
                'speech_to_text' => 'declared_simulated',
                'text_to_speech' => 'placeholder',
                'intent_detection' => 'keyword_mvp_limited',
            ],
        ];
    }

    public function normalizeLanguage(?string $language): string
    {
        $language = mb_strtolower(trim((string) $language));

        return match (true) {
            str_starts_with($language, 'en') => 'en',
            str_starts_with($language, 'wo') => 'wo',
            default => 'fr',
        };
    }

    public function detectLanguage(string $text, ?string $preferredLanguage = null): string
    {
        if ($preferredLanguage !== null && trim($preferredLanguage) !== '') {
            return $this->normalizeLanguage($preferredLanguage);
        }

        $normalized = $this->normalizeText($text);

        if (preg_match('/\b(sama|wallet bi|xaalis|yonnee|marse|cheque bi|sama solde)\b/', $normalized)) {
            return 'wo';
        }

        if (preg_match('/\b(voir|montre|dernieres|mon|mes|pret|marche|envoyer|ou en est|etat du)\b/', $normalized)) {
            return 'fr';
        }

        if (preg_match('/\b(balance|latest|history|loan|market|transfer|send|check status|show me|can i)\b/', $normalized)) {
            return 'en';
        }

        return 'fr';
    }

    public function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        if ($normalized === false) {
            $normalized = $text;
        }

        $normalized = strtr($normalized, [
            "'" => ' ',
            '-' => ' ',
        ]);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
