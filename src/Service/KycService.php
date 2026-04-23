<?php

namespace App\Service;

use App\Entity\User\Client\Kyc;
use App\Entity\User\Client\KycFile;
use App\Entity\User\User;
use App\Repository\KycRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service métier — Gestion du KYC.
 *
 * Gère la soumission, l'approbation et le refus des dossiers KYC.
 * Les fichiers sont stockés en base de données (BLOB).
 */
class KycService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KycRepository          $kycRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string                 $projectDir,
    ) {}

    /**
     * Soumet un nouveau dossier KYC pour un utilisateur.
     * Persiste le KYC et ses fichiers justificatifs en base.
     *
     * @param UploadedFile[] $uploadedFiles
     */
    public function submitKyc(User $user, Kyc $kyc, array $uploadedFiles, string $signatureData): void
    {
        $kyc->setUser($user);
        $kyc->setStatut(Kyc::STATUT_EN_ATTENTE);
        $kyc->setDateSubmission(new \DateTime());
        $this->storeSignature($kyc, $signatureData);

        // Persistance de chaque fichier justificatif
        foreach ($uploadedFiles as $uploadedFile) {
            $kycFile = new KycFile();
            $kycFile->setFileName($uploadedFile->getClientOriginalName());
            $kycFile->setFileType($uploadedFile->getMimeType() ?? 'application/octet-stream');
            $kycFile->setFileSize($uploadedFile->getSize());
            $kycFile->setFileData(file_get_contents($uploadedFile->getPathname()));
            $kycFile->setUpdatedAt(new \DateTime());
            $kyc->addFile($kycFile);
            $this->em->persist($kycFile);
        }

        $this->em->persist($kyc);

        // Mise à jour du statut KYC sur l'utilisateur
        $user->setKycStatus(User::KYC_EN_ATTENTE);
        $this->em->flush();

        // Mise à jour de l'ID KYC courant après flush (ID généré par la BDD)
        $user->setCurrentKycId($kyc->getId());
        $this->em->flush();
    }

    private function storeSignature(Kyc $kyc, string $signatureData): void
    {
        if (!preg_match('/^data:image\/png;base64,/', $signatureData)) {
            return;
        }

        $binary = base64_decode(substr($signatureData, strpos($signatureData, ',') + 1), true);
        if ($binary === false) {
            return;
        }

        $directory = $this->projectDir . '/public/uploads/kyc-signatures';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filename = 'signature_' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($directory . '/' . $filename, $binary);

        $kyc->setSignaturePath('uploads/kyc-signatures/' . $filename);
        $kyc->setSignatureUploadedAt(new \DateTime());
    }

    /**
     * Approuve un dossier KYC et active le compte utilisateur.
     */
    public function approveKyc(Kyc $kyc, ?string $commentaire = null): void
    {
        $kyc->setStatut(Kyc::STATUT_APPROUVE);
        $kyc->setCommentaireAdmin($commentaire);

        $user = $kyc->getUser();
        $user->setKycStatus(User::KYC_APPROUVE);
        $user->setStatus(User::STATUS_ACTIF);

        $this->em->flush();
    }

    /**
     * Refuse un dossier KYC avec un commentaire obligatoire.
     */
    public function refuseKyc(Kyc $kyc, string $commentaire): void
    {
        $kyc->setStatut(Kyc::STATUT_REFUSE);
        $kyc->setCommentaireAdmin($commentaire);

        $user = $kyc->getUser();
        $user->setKycStatus(User::KYC_REFUSE);

        $this->em->flush();
    }

    /**
     * Retourne le contenu d'un fichier KYC encodé en base64 pour affichage inline.
     */
    public function getFileBase64(KycFile $file): string
    {
        $data = $file->getFileData();
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return base64_encode((string) $data);
    }
}
