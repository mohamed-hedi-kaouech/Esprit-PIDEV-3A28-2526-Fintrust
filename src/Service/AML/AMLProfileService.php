<?php

namespace App\Service\AML;

use App\Entity\Wallet\Wallet;

/**
 * Classe le wallet dans un profil de conformité AML (normal → critique).
 */
class AMLProfileService
{
    private const PROFILES = [
        'critique' => [
            'code'             => 'critique',
            'label'            => 'Critique',
            'badge'            => 'ft-badge-danger',
            'icon'             => 'bi-shield-x',
            'color'            => '#dc2626',
            'description'      => 'Profil à risque maximal. Plusieurs signaux AML critiques confirmés.',
            'compliance_note'  => 'Dossier SAR recommandé. Blocage immédiat à considérer.',
        ],
        'risque' => [
            'code'             => 'risque',
            'label'            => 'Risqué',
            'badge'            => 'ft-badge-warning',
            'icon'             => 'bi-shield-exclamation',
            'color'            => '#b91c1c',
            'description'      => 'Profil à risque élevé. Comportement non conforme aux patterns habituels.',
            'compliance_note'  => 'Vérification manuelle obligatoire. Surveillance renforcée.',
        ],
        'sensible' => [
            'code'             => 'sensible',
            'label'            => 'Sensible',
            'badge'            => 'ft-badge-orange',
            'icon'             => 'bi-shield-half',
            'color'            => '#d97706',
            'description'      => 'Profil sensible. Signaux secondaires présents nécessitant attention.',
            'compliance_note'  => 'Surveillance active et revue de compte recommandées.',
        ],
        'a_surveiller' => [
            'code'             => 'a_surveiller',
            'label'            => 'A surveiller',
            'badge'            => 'ft-badge-info',
            'icon'             => 'bi-shield',
            'color'            => '#2563eb',
            'description'      => 'Profil nécessitant surveillance. Quelques indicateurs atypiques relevés.',
            'compliance_note'  => 'Suivi régulier conseillé.',
        ],
        'normal' => [
            'code'             => 'normal',
            'label'            => 'Normal',
            'badge'            => 'ft-badge-success',
            'icon'             => 'bi-shield-check',
            'color'            => '#16a34a',
            'description'      => 'Profil conforme. Aucun signal AML significatif détecté.',
            'compliance_note'  => 'Surveillance standard active.',
        ],
    ];

    public function classify(int $amlScore, string $amlLevel, array $alerts, Wallet $wallet): array
    {
        $profileCode = $this->resolveCode($amlScore, $amlLevel, $alerts, $wallet);
        return array_merge(self::PROFILES[$profileCode], ['resolved_at' => new \DateTimeImmutable()]);
    }

    private function resolveCode(int $score, string $level, array $alerts, Wallet $wallet): string
    {
        $criticalAlerts = count(array_filter($alerts, static fn($a) => $a['severity'] === 'critical'));

        if ($score >= 76 || ($criticalAlerts >= 2)) {
            return 'critique';
        }
        if ($score >= 60 || ($criticalAlerts >= 1 && $score >= 45)) {
            return 'risque';
        }
        if ($score >= 40 || $level === 'eleve') {
            return 'sensible';
        }
        if ($score >= 20 || $level === 'moyen') {
            return 'a_surveiller';
        }
        return 'normal';
    }
}
