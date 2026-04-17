<?php

namespace App\Service;

use App\Entity\User\User;

class ComplianceCopilotService
{
    public function __construct(
        private readonly UserIntelligenceService $userIntelligenceService,
        private readonly KycVerificationCenterService $kycVerificationCenterService,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generateReview(?User $user = null, array $payload = []): array
    {
        $profile = $user ? $this->userIntelligenceService->buildProfileEnrichment($user) : (array) ($payload['profilClient'] ?? []);
        $riskProfile = $user ? $this->userIntelligenceService->getRiskProfile($user) : $this->buildFallbackRiskProfile($payload);
        $behavior = $user ? $this->userIntelligenceService->getFinancialBehaviorSummary($user) : $this->buildFallbackBehavior($payload);
        $kycCenter = $user ? $this->kycVerificationCenterService->buildCenter($user) : $this->buildFallbackKycCenter($payload);

        $documentIssues = $this->normalizeList($payload['anomaliesDocumentaires'] ?? $kycCenter['document']['issues'] ?? []);
        $transactionSignals = $this->normalizeList($payload['signauxTransactionnels'] ?? $behavior['anomalyFlags'] ?? []);
        $existingAlerts = $this->normalizeList($payload['alertesExistantes'] ?? $riskProfile['alerts'] ?? []);
        $monitoringFlags = $this->normalizeList($profile['monitoringFlags'] ?? []);
        $history = $this->normalizeHistory($payload['historiqueRecent'] ?? $riskProfile['history'] ?? []);

        $keySignals = array_values(array_unique(array_filter(array_merge(
            $documentIssues,
            $transactionSignals,
            $existingAlerts,
            $monitoringFlags,
            $this->deriveCoreSignals($profile, $riskProfile, $kycCenter)
        ))));

        $riskLevel = strtoupper((string) ($payload['niveauRisque'] ?? $riskProfile['riskLevel'] ?? 'LOW'));
        $recommendedDecision = $this->resolveDecision($user, $payload, $riskLevel, $kycCenter, $documentIssues, $transactionSignals, $existingAlerts);
        $justification = $this->buildJustification($recommendedDecision, $riskLevel, $keySignals, $kycCenter);

        return [
            'caseId' => $this->buildCaseId($user, $payload),
            'userId' => $user?->getId(),
            'profileName' => $user?->getFullName() ?? (string) ($payload['profilClient']['nomComplet'] ?? 'Dossier manuel'),
            'recommendedDecision' => $recommendedDecision,
            'riskLevel' => $riskLevel,
            'summary' => $this->buildSummary($user, $profile, $riskProfile, $kycCenter),
            'keySignals' => array_slice($keySignals, 0, 5),
            'elementsOfVigilance' => array_slice(array_values(array_unique(array_merge($documentIssues, $transactionSignals, $existingAlerts))), 0, 4),
            'justification' => $justification,
            'generatedReview' => $this->buildGeneratedReview($user, $recommendedDecision, $riskLevel, $kycCenter, $keySignals, $history),
            'history' => array_slice($history, 0, 4),
            'recommendedActions' => $this->buildRecommendedActions($recommendedDecision, $kycCenter, $documentIssues),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSampleCases(): array
    {
        return [
            [
                'label' => 'Dossier approuvable',
                'recommendedDecision' => 'APPROVE',
                'riskLevel' => 'LOW',
                'keySignals' => [
                    'document d identite lisible et coherent',
                    'aucune anomalie transactionnelle recente',
                    'profil stable avec KYC complet',
                ],
                'justification' => 'Le dossier est coherent, la pression risque reste faible et aucun point bloquant n apparait a ce stade.',
            ],
            [
                'label' => 'Dossier a revoir',
                'recommendedDecision' => 'REVIEW',
                'riskLevel' => 'MEDIUM',
                'keySignals' => [
                    'confiance documentaire moyenne',
                    'variation recente du comportement transactionnel',
                    'justificatif secondaire a confirmer',
                ],
                'justification' => 'Les fondations du dossier sont globalement recevables, mais une validation humaine complementaire reste recommandee.',
            ],
            [
                'label' => 'Dossier a rejeter',
                'recommendedDecision' => 'REJECT',
                'riskLevel' => 'HIGH',
                'keySignals' => [
                    'incoherence entre donnees declarees et document',
                    'document douteux ou incomplet',
                    'signaux fraude eleves',
                ],
                'justification' => 'La combinaison des incoherences identitaires et des signaux de fraude ne permet pas une validation dans l etat.',
            ],
            [
                'label' => 'Document douteux',
                'recommendedDecision' => 'REVIEW',
                'riskLevel' => 'MEDIUM',
                'keySignals' => [
                    'qualite d extraction insuffisante',
                    'numero de document partiellement ambigu',
                    'revue manuelle recommandee',
                ],
                'justification' => 'Le dossier n est pas rejetable immediatement mais la base documentaire ne permet pas encore une approbation robuste.',
            ],
            [
                'label' => 'Comportement atypique',
                'recommendedDecision' => 'REVIEW',
                'riskLevel' => 'HIGH',
                'keySignals' => [
                    'hausse brusque des montants moyens',
                    'frequence transactionnelle inhabituellement elevee',
                    'alerte comportementale recente',
                ],
                'justification' => 'La pression comportementale recente sort de la norme habituelle et justifie une analyse complementaire.',
            ],
            [
                'label' => 'Incoherence identitaire',
                'recommendedDecision' => 'REJECT',
                'riskLevel' => 'HIGH',
                'keySignals' => [
                    'donnees declarees et document non coherentes',
                    'selfie et document non conclusifs',
                    'niveau de confiance global insuffisant',
                ],
                'justification' => 'Les elements d identite ne convergent pas suffisamment pour poursuivre vers une approbation.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildFallbackRiskProfile(array $payload): array
    {
        $riskLevel = strtoupper((string) ($payload['niveauRisque'] ?? 'MEDIUM'));

        return [
            'riskLevel' => $riskLevel,
            'alerts' => $this->normalizeList($payload['alertesExistantes'] ?? []),
            'history' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildFallbackBehavior(array $payload): array
    {
        return [
            'anomalyFlags' => $this->normalizeList($payload['signauxTransactionnels'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildFallbackKycCenter(array $payload): array
    {
        $status = strtoupper((string) ($payload['statutKyc'] ?? 'IN_REVIEW'));

        return [
            'overallKycStatus' => $status,
            'document' => [
                'issues' => $this->normalizeList($payload['anomaliesDocumentaires'] ?? []),
                'documentStatus' => $status === 'VERIFIED' ? 'VERIFIED' : 'IN_REVIEW',
            ],
            'decision' => [
                'status' => $status,
                'anomalies' => $this->normalizeList($payload['anomaliesDocumentaires'] ?? []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $riskProfile
     * @param array<string, mixed> $kycCenter
     * @return list<string>
     */
    private function deriveCoreSignals(array $profile, array $riskProfile, array $kycCenter): array
    {
        $signals = [];

        if (($profile['segment'] ?? null) === 'VIP') {
            $signals[] = 'segment premium avec comportement historiquement stable';
        }

        if (($kycCenter['overallKycStatus'] ?? null) === 'INCOMPLETE') {
            $signals[] = 'KYC incomplet ou partiellement exploitable';
        }

        if (($riskProfile['riskLevel'] ?? 'LOW') === 'CRITICAL') {
            $signals[] = 'niveau de risque critique necessitant une escalade immediate';
        } elseif (($riskProfile['riskLevel'] ?? 'LOW') === 'HIGH') {
            $signals[] = 'niveau de risque eleve a surveiller';
        }

        return $signals;
    }

    /**
     * @param list<string> $documentIssues
     * @param list<string> $transactionSignals
     * @param list<string> $existingAlerts
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $kycCenter
     */
    private function resolveDecision(?User $user, array $payload, string $riskLevel, array $kycCenter, array $documentIssues, array $transactionSignals, array $existingAlerts): string
    {
        $combined = strtolower(implode(' ', array_merge($documentIssues, $transactionSignals, $existingAlerts)));
        $kycStatus = strtoupper((string) ($payload['statutKyc'] ?? $kycCenter['overallKycStatus'] ?? $user?->getKycStatus() ?? 'IN_REVIEW'));

        $hasSevereDocumentIssue = preg_match('/douteux|faux|fraude|incoheren|manquan|insuffisant|rejete/', $combined) === 1;
        $hasBehaviorAnomaly = preg_match('/variation|atypique|inhabituel|alerte|brusque/', $combined) === 1;

        if ($kycStatus === 'REJECTED' || $kycStatus === 'REFUSE' || $riskLevel === 'CRITICAL' || $hasSevereDocumentIssue && $riskLevel === 'HIGH') {
            return 'REJECT';
        }

        if (
            in_array($kycStatus, ['IN_REVIEW', 'PROCESSING', 'PENDING', 'INCOMPLETE', 'EN_ATTENTE'], true)
            || in_array($riskLevel, ['MEDIUM', 'HIGH'], true)
            || $documentIssues !== []
            || $hasBehaviorAnomaly
        ) {
            return 'REVIEW';
        }

        return 'APPROVE';
    }

    /**
     * @param list<string> $signals
     * @param array<string, mixed> $kycCenter
     */
    private function buildJustification(string $decision, string $riskLevel, array $signals, array $kycCenter): string
    {
        return match ($decision) {
            'APPROVE' => 'Le dossier presente un niveau de coherence satisfaisant, un risque ' . strtolower($riskLevel) . ' et aucun element bloquant majeur. Une approbation est envisageable dans l etat.',
            'REJECT' => 'Le dossier cumule des signaux incompatibles avec une validation prudente. La combinaison des anomalies documentaires et du niveau de risque justifie un rejet en l etat.',
            default => 'Le dossier presente des elements globalement coherents, mais certaines zones demandent une analyse complementaire avant toute validation finale. Statut KYC actuel: ' . strtolower((string) ($kycCenter['overallKycStatus'] ?? 'in_review')) . '.',
        };
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $riskProfile
     * @param array<string, mixed> $kycCenter
     */
    private function buildSummary(?User $user, array $profile, array $riskProfile, array $kycCenter): string
    {
        if ($user instanceof User) {
            return sprintf(
                '%s, segment %s, trust score %s/100, statut KYC %s.',
                $user->getFullName(),
                strtolower((string) ($profile['segment'] ?? $user->getClientSegment())),
                (string) ($profile['trustScore'] ?? 'n/a'),
                strtolower((string) ($kycCenter['overallKycStatus'] ?? $user->getKycStatus() ?? 'incomplet'))
            );
        }

        return 'Dossier manuel en cours d analyse, avec synthese des signaux disponibles.';
    }

    /**
     * @param list<string> $signals
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $kycCenter
     */
    private function buildGeneratedReview(?User $user, string $decision, string $riskLevel, array $kycCenter, array $signals, array $history): string
    {
        $subject = $user?->getFullName() ?? 'Le dossier client';
        $signalText = $signals !== [] ? implode(', ', array_slice($signals, 0, 3)) : 'aucun signal bloquant majeur';
        $historyText = $history !== [] ? ' Historique recent: ' . (string) ($history[0]['label'] ?? $history[0]['step'] ?? 'dernier evenement analyse') . '.' : '';

        return sprintf(
            "Apres analyse du dossier %s, les informations d identite apparaissent %s. Les signaux cles observes sont les suivants : %s. Le niveau de risque est evalue a %s et le statut KYC actuel est %s. En conclusion, une decision de type %s est recommandee.%s",
            $subject,
            $decision === 'APPROVE' ? 'coherentes et globalement rassurantes' : ($decision === 'REJECT' ? 'insuffisamment coherentes pour une validation' : 'globalement recevables mais encore partielles'),
            $signalText,
            strtolower($riskLevel),
            strtolower((string) ($kycCenter['overallKycStatus'] ?? 'in_review')),
            $decision,
            $historyText
        );
    }

    /**
     * @param list<string> $documentIssues
     * @return list<string>
     */
    private function buildRecommendedActions(string $decision, array $kycCenter, array $documentIssues): array
    {
        if ($decision === 'APPROVE') {
            return ['Valider le dossier', 'Archiver la revue', 'Maintenir une surveillance standard'];
        }

        if ($decision === 'REJECT') {
            return ['Notifier le client', 'Demander un nouveau depot documentaire', 'Conserver une trace de rejet cote conformite'];
        }

        $actions = ['Passer en revue manuelle', 'Verifier les pieces justificatives', 'Confirmer les signaux recents'];
        if ($documentIssues !== []) {
            $actions[] = 'Relancer un controle documentaire cible';
        }
        if (($kycCenter['overallKycStatus'] ?? null) === 'INCOMPLETE') {
            $actions[] = 'Completer les pieces KYC manquantes';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function normalizeHistory(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $history = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $history[] = $item;
            }
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildCaseId(?User $user, array $payload): string
    {
        if ($user instanceof User) {
            return sprintf('cmp_%03d', $user->getId());
        }

        return 'cmp_' . substr(md5(json_encode($payload)), 0, 6);
    }
}
