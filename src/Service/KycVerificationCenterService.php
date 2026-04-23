<?php

namespace App\Service;

use App\Entity\User\Client\Kyc;
use App\Entity\User\Client\KycFile;
use App\Entity\User\User;
use App\Repository\KycRepository;

class KycVerificationCenterService
{
    public function __construct(
        private readonly KycRepository $kycRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildCenter(User $user): array
    {
        $kyc = $this->kycRepository->findLatestByUser($user);

        if (!$kyc) {
            return [
                'userId' => $user->getId(),
                'overallKycStatus' => 'INCOMPLETE',
                'progression' => 12,
                'lastUpdatedAt' => null,
                'document' => [
                    'documentType' => 'Non fourni',
                    'issuingCountry' => 'TN',
                    'documentNumberMasked' => null,
                    'expiryDate' => null,
                    'documentStatus' => 'INCOMPLETE',
                    'confidenceScore' => 0,
                    'issues' => ['Aucun document verse au dossier.'],
                ],
                'selfieMatch' => [
                    'selfieMatchScore' => null,
                    'selfieMatchStatus' => 'INCOMPLETE',
                    'confidenceLevel' => 'Non disponible',
                    'reviewRequired' => true,
                    'message' => 'Aucun selfie exploitable n a ete detecte dans le stockage KYC.',
                ],
                'decision' => [
                    'status' => 'INCOMPLETE',
                    'motifs' => ['Pieces manquantes'],
                    'anomalies' => ['Depot KYC non initie'],
                    'recommendations' => ['Inviter le client a soumettre ses justificatifs'],
                ],
                'history' => [],
            ];
        }

        $document = $this->buildDocumentVerification($kyc);
        $selfieMatch = $this->buildSelfieMatch($kyc);
        $decision = $this->buildDecision($kyc, $document, $selfieMatch);

        return [
            'userId' => $user->getId(),
            'overallKycStatus' => $decision['status'],
            'progression' => $this->computeProgression($kyc, $document, $selfieMatch),
            'lastUpdatedAt' => $kyc->getDateSubmission()->format(\DateTimeInterface::ATOM),
            'document' => $document,
            'selfieMatch' => $selfieMatch,
            'decision' => $decision,
            'history' => $this->buildHistory($kyc, $document, $selfieMatch, $decision),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function verifyDocumentFromPayload(array $payload): array
    {
        $documentType = strtoupper((string) ($payload['documentType'] ?? 'CIN'));
        $documentImageUrl = (string) ($payload['documentImageUrl'] ?? '');
        $userId = (string) ($payload['userId'] ?? '');
        $isSupported = (bool) preg_match('/\.(jpg|jpeg|png|pdf)$/i', $documentImageUrl);

        $confidence = $isSupported ? 94 : 38;
        $issues = [];
        if (!$isSupported) {
            $issues[] = 'Format de document non reconnu';
        }
        if ($documentImageUrl === '') {
            $issues[] = 'URL du document absente';
        }

        return [
            'userId' => $userId,
            'documentStatus' => $issues === [] ? 'VERIFIED' : 'IN_REVIEW',
            'documentType' => $documentType,
            'documentNumberMasked' => '12******89',
            'expiryDate' => '2030-06-01',
            'issuingCountry' => 'TN',
            'confidenceScore' => $confidence,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function verifySelfieMatchFromPayload(array $payload): array
    {
        $userId = (string) ($payload['userId'] ?? '');
        $selfieImageUrl = strtolower((string) ($payload['selfieImageUrl'] ?? ''));
        $documentFaceImageUrl = strtolower((string) ($payload['documentFaceImageUrl'] ?? ''));

        $hasSelfie = $selfieImageUrl !== '';
        $hasDocumentFace = $documentFaceImageUrl !== '';
        $keywordsAligned = str_contains($selfieImageUrl, 'selfie') || str_contains($selfieImageUrl, 'portrait') || str_contains($selfieImageUrl, 'face');

        $score = $hasSelfie && $hasDocumentFace ? ($keywordsAligned ? 94 : 82) : 35;
        $status = $score >= 90 ? 'VERIFIED' : ($score >= 70 ? 'IN_REVIEW' : 'REJECTED');

        return [
            'userId' => $userId,
            'selfieMatchScore' => $score,
            'selfieMatchStatus' => $status,
            'reviewRequired' => $status !== 'VERIFIED',
            'message' => $status === 'VERIFIED'
                ? 'Correspondance biométrique validée.'
                : ($status === 'IN_REVIEW'
                    ? 'Correspondance partielle, revue manuelle conseillée.'
                    : 'Correspondance insuffisante ou fichiers manquants.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyIdentity(User $user): array
    {
        $center = $this->buildCenter($user);

        return [
            'userId' => sprintf('usr_%03d', $user->getId()),
            'documentStatus' => $center['document']['documentStatus'],
            'selfieMatchStatus' => $center['selfieMatch']['selfieMatchStatus'],
            'overallKycStatus' => $center['overallKycStatus'],
            'reviewReason' => implode(', ', $center['decision']['anomalies']),
            'verificationSource' => 'Interne mockable',
            'verifiedAt' => $center['lastUpdatedAt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocumentVerification(Kyc $kyc): array
    {
        $documentFile = $this->pickDocumentFile($kyc);
        $issues = [];

        if (!$documentFile) {
            $issues[] = 'Aucun justificatif documentaire exploitable detecte';
        }

        $documentStatus = $issues === [] ? 'VERIFIED' : 'IN_REVIEW';
        $confidence = $documentFile ? 92 : 40;
        if ($kyc->getFiles()->count() >= 2) {
            $confidence += 3;
        }
        if (strlen($kyc->getCin()) === 8) {
            $confidence += 2;
        }

        return [
            'documentType' => $this->inferDocumentType($documentFile?->getFileName()),
            'issuingCountry' => 'TN',
            'documentNumberMasked' => $this->maskCin($kyc->getCin()),
            'expiryDate' => null,
            'documentStatus' => $documentStatus,
            'confidenceScore' => min(98, $confidence),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSelfieMatch(Kyc $kyc): array
    {
        $selfie = $this->pickSelfieFile($kyc);
        if (!$selfie) {
            return [
                'selfieMatchScore' => null,
                'selfieMatchStatus' => 'INCOMPLETE',
                'confidenceLevel' => 'Faible',
                'reviewRequired' => true,
                'message' => 'Aucun selfie explicite detecte dans les fichiers stockes.',
            ];
        }

        $score = 88;
        if ($kyc->getFiles()->count() >= 2) {
            $score += 4;
        }
        if (str_contains(strtolower($selfie->getFileName()), 'selfie')) {
            $score += 3;
        }

        $status = $score >= 92 ? 'VERIFIED' : 'IN_REVIEW';

        return [
            'selfieMatchScore' => min(97, $score),
            'selfieMatchStatus' => $status,
            'confidenceLevel' => $status === 'VERIFIED' ? 'Elevee' : 'Moderee',
            'reviewRequired' => $status !== 'VERIFIED',
            'message' => $status === 'VERIFIED'
                ? 'Correspondance selfie-document convaincante.'
                : 'Le selfie semble exploitable mais demande une validation manuelle.',
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $selfieMatch
     * @return array<string, mixed>
     */
    private function buildDecision(Kyc $kyc, array $document, array $selfieMatch): array
    {
        $status = match ($kyc->getStatut()) {
            Kyc::STATUT_APPROUVE => 'VERIFIED',
            Kyc::STATUT_REFUSE => 'REJECTED',
            default => ($document['documentStatus'] === 'VERIFIED' && $selfieMatch['selfieMatchStatus'] === 'VERIFIED')
                ? 'IN_REVIEW'
                : 'INCOMPLETE',
        };

        $motifs = [];
        $anomalies = [];
        $recommendations = [];

        if ($document['documentStatus'] !== 'VERIFIED') {
            $motifs[] = 'Verification documentaire partielle';
            $anomalies[] = 'Document a confirmer';
            $recommendations[] = 'Relancer un controle documentaire';
        }
        if ($selfieMatch['selfieMatchStatus'] !== 'VERIFIED') {
            $motifs[] = 'Correspondance selfie non definitive';
            $anomalies[] = 'Biometrie a valider';
            $recommendations[] = 'Passer en revue manuelle';
        }
        if ($kyc->getCommentaireAdmin()) {
            $motifs[] = $kyc->getCommentaireAdmin();
        }
        if ($status === 'VERIFIED') {
            $motifs[] = 'Dossier conforme et approuve';
            $recommendations[] = 'Aucune action supplementaire';
        }
        if ($status === 'REJECTED') {
            $recommendations[] = 'Demander un nouveau depot client';
        }

        return [
            'status' => $status,
            'motifs' => $motifs,
            'anomalies' => $anomalies,
            'recommendations' => array_values(array_unique($recommendations)),
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $selfieMatch
     * @param array<string, mixed> $decision
     * @return array<int, array<string, mixed>>
     */
    private function buildHistory(Kyc $kyc, array $document, array $selfieMatch, array $decision): array
    {
        $history = [
            [
                'date' => $kyc->getDateSubmission()->format('d/m/Y H:i'),
                'step' => 'Depot du dossier',
                'result' => 'Dossier recu',
                'source' => 'Client',
            ],
            [
                'date' => $kyc->getDateSubmission()->format('d/m/Y H:i'),
                'step' => 'Verification documentaire',
                'result' => $document['documentStatus'],
                'source' => 'Interne',
            ],
            [
                'date' => $kyc->getDateSubmission()->format('d/m/Y H:i'),
                'step' => 'Correspondance selfie-document',
                'result' => $selfieMatch['selfieMatchStatus'],
                'source' => 'Interne',
            ],
            [
                'date' => $kyc->getDateSubmission()->format('d/m/Y H:i'),
                'step' => 'Decision KYC',
                'result' => $decision['status'],
                'source' => $kyc->getCommentaireAdmin() ? 'Admin' : 'Systeme',
            ],
        ];

        return $history;
    }

    private function computeProgression(Kyc $kyc, array $document, array $selfieMatch): int
    {
        $progress = 35;
        if ($document['documentStatus'] === 'VERIFIED') {
            $progress += 30;
        }
        if ($selfieMatch['selfieMatchStatus'] === 'VERIFIED') {
            $progress += 25;
        }
        if ($kyc->getStatut() === Kyc::STATUT_APPROUVE) {
            $progress = 100;
        } elseif ($kyc->getStatut() === Kyc::STATUT_REFUSE) {
            $progress = 82;
        }

        return min(100, $progress);
    }

    private function pickDocumentFile(Kyc $kyc): ?KycFile
    {
        foreach ($kyc->getFiles() as $file) {
            if (!$this->looksLikeSelfie($file)) {
                return $file;
            }
        }

        return $kyc->getFiles()->first() ?: null;
    }

    private function pickSelfieFile(Kyc $kyc): ?KycFile
    {
        foreach ($kyc->getFiles() as $file) {
            if ($this->looksLikeSelfie($file)) {
                return $file;
            }
        }

        return null;
    }

    private function looksLikeSelfie(KycFile $file): bool
    {
        $name = strtolower($file->getFileName());

        return str_contains($name, 'selfie')
            || str_contains($name, 'visage')
            || str_contains($name, 'portrait')
            || str_contains($name, 'face');
    }

    private function inferDocumentType(?string $fileName): string
    {
        $fileName = strtolower((string) $fileName);
        if (str_contains($fileName, 'passport') || str_contains($fileName, 'passeport')) {
            return 'PASSEPORT';
        }

        return 'CIN';
    }

    private function maskCin(string $cin): string
    {
        if (strlen($cin) < 4) {
            return str_repeat('*', strlen($cin));
        }

        return substr($cin, 0, 2) . str_repeat('*', max(0, strlen($cin) - 4)) . substr($cin, -2);
    }
}
