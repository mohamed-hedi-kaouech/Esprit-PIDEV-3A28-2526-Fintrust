<?php

namespace App\Service\AML;

use App\Entity\Wallet\Wallet;

/**
 * Génère la recommandation admin adaptée au profil AML d'un wallet.
 */
class AMLRecommendationService
{
    public function recommend(int $amlScore, string $profileCode, array $alerts, Wallet $wallet): array
    {
        $hasHighAlert = !empty(array_filter($alerts, static fn($a) => $a['severity'] === 'high'));

        return match ($profileCode) {
            'critique' => $this->build(
                'blocage_conseille',
                'Blocage administratif conseillé',
                'critical',
                [
                    'Déclencher un blocage immédiat du wallet pour investigation',
                    'Ouvrir un dossier de signalement (SAR) auprès de la CTAF',
                    'Contacter le compliance officer pour validation',
                    'Geler les opérations en attente (chèques, transferts)',
                    'Notifier le service KYC pour révision complète du dossier client',
                ],
                'AMLA Tunisie – Art. 38 : signalement obligatoire en cas de présomption de blanchiment.'
            ),
            'risque' => $this->build(
                'verification_manuelle',
                'Vérification manuelle requise',
                'high',
                [
                    'Déclencher une revue manuelle complète du compte',
                    'Vérifier l\'origine des fonds sur les 30 derniers jours',
                    'Contacter le client pour justification des mouvements atypiques',
                    'Activer la surveillance renforcée (Enhanced Due Diligence)',
                    'Documenter toutes les décisions dans le dossier compliance',
                ],
                'Directive AML/KYC : Enhanced Due Diligence obligatoire pour profils à risque élevé.'
            ),
            'sensible' => $this->build(
                'surveillance_renforcee',
                'Surveillance renforcée',
                'medium',
                [
                    'Activer le suivi hebdomadaire du compte',
                    'Paramétrer des alertes automatiques sur les mouvements > 1 000 TND',
                    'Planifier une revue du profil client sous 30 jours',
                    'Vérifier la conformité KYC du client',
                ],
                'Mesure de vigilance renforcée recommandée par les bonnes pratiques AML.'
            ),
            'a_surveiller' => $hasHighAlert
                ? $this->build(
                    'analyse_complementaire',
                    'Analyse complémentaire recommandée',
                    'medium',
                    [
                        'Examiner les alertes de niveau haut avant la prochaine opération importante',
                        'Valider la cohérence des transactions récentes avec le profil client',
                        'Planifier une revue sous 14 jours',
                    ],
                    'Vigilance standard renforcée — alertes secondaires détectées.'
                )
                : $this->build(
                    'surveillance_standard',
                    'Surveillance standard',
                    'low',
                    [
                        'Continuer le monitoring automatique habituel',
                        'Revoir le profil lors de la prochaine transaction significative',
                    ],
                    'Profil dans la norme. Aucune action urgente.'
                ),
            default => $this->build(
                'aucune_action',
                'Aucune action requise',
                'info',
                ['Wallet conforme. Surveillance automatique standard active.'],
                'Profil conforme aux standards AML.'
            ),
        };
    }

    private function build(string $code, string $label, string $priority, array $steps, string $regulatoryBasis): array
    {
        return [
            'code'             => $code,
            'label'            => $label,
            'priority'         => $priority,
            'steps'            => $steps,
            'regulatory_basis' => $regulatoryBasis,
            'generated_at'     => new \DateTimeImmutable(),
        ];
    }
}
