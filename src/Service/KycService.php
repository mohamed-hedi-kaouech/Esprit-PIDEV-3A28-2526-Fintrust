<?php

namespace App\Service;

use App\Entity\User\Client\Kyc;
use App\Entity\User\Client\KycFile;
use App\Entity\User\User;
use App\Repository\KycRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service metier - Gestion du KYC.
 *
 * Gere la soumission, l'approbation et le refus des dossiers KYC.
 * Les fichiers sont stockes sur le serveur et seul leur chemin est conserve en base.
 */
class KycService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KycRepository $kycRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /**
     * @param UploadedFile[] $uploadedFiles
     */
    public function submitKyc(User $user, Kyc $kyc, array $uploadedFiles, string $signatureData, string $selfieData = '', string $selfieFingerprintData = ''): void
    {
        $kyc->setUser($user);
        $kyc->setStatut(Kyc::STATUT_EN_ATTENTE);
        $kyc->setDateSubmission(new \DateTime());
        $this->storeSignature($kyc, $signatureData);

        foreach ($uploadedFiles as $uploadedFile) {
            $kycFile = new KycFile();
            $kycFile->setFileName($uploadedFile->getClientOriginalName());
            $kycFile->setFileType($uploadedFile->getMimeType() ?? 'application/octet-stream');
            $kycFile->setFileSize((int) ($uploadedFile->getSize() ?? 0));
            $kycFile->setFilePath($this->storeUploadedFile($uploadedFile));
            $kycFile->setUpdatedAt(new \DateTime());
            $kyc->addFile($kycFile);
            $this->em->persist($kycFile);
        }

        $this->storeSelfieReference($kyc, $selfieData, $selfieFingerprintData);

        $this->em->persist($kyc);

        $user->setKycStatus(User::KYC_EN_ATTENTE);
        $this->em->flush();

        $user->setCurrentKycId($kyc->getId());
        $this->em->flush();
    }

    private function storeUploadedFile(UploadedFile $uploadedFile): string
    {
        $directory = $this->projectDir . '/public/uploads/kyc-files';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $safeBaseName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $safeBaseName) ?: 'kyc_file';
        $extension = $uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension() ?: 'bin';
        $filename = sprintf('%s_%s.%s', $safeBaseName, bin2hex(random_bytes(8)), $extension);

        $uploadedFile->move($directory, $filename);

        return 'uploads/kyc-files/' . $filename;
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

    private function storeSelfieReference(Kyc $kyc, string $selfieData, string $selfieFingerprintData): void
    {
        if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $selfieData)) {
            return;
        }

        if ($selfieFingerprintData === '') {
            return;
        }

        $binary = base64_decode(substr($selfieData, strpos($selfieData, ',') + 1), true);
        if ($binary === false || $binary === '') {
            return;
        }

        $directory = $this->projectDir . '/public/uploads/kyc-files';
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filename = 'selfie_reference_' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($directory . '/' . $filename, $binary);
        file_put_contents($directory . '/' . $filename . '.json', $selfieFingerprintData);

        $kycFile = new KycFile();
        $kycFile->setFileName($filename);
        $kycFile->setFileType('image/png');
        $kycFile->setFileSize(strlen($binary));
        $kycFile->setFilePath('uploads/kyc-files/' . $filename);
        $kycFile->setUpdatedAt(new \DateTime());
        $kyc->addFile($kycFile);
        $this->em->persist($kycFile);
    }

    public function approveKyc(Kyc $kyc, ?string $commentaire = null): void
    {
        $kyc->setStatut(Kyc::STATUT_APPROUVE);
        $kyc->setCommentaireAdmin($commentaire);

        $user = $kyc->getUser();
        $user->setKycStatus(User::KYC_APPROUVE);
        $user->setStatus(User::STATUS_ACTIF);

        $this->em->flush();
    }

    public function refuseKyc(Kyc $kyc, string $commentaire): void
    {
        $kyc->setStatut(Kyc::STATUT_REFUSE);
        $kyc->setCommentaireAdmin($commentaire);

        $user = $kyc->getUser();
        $user->setKycStatus(User::KYC_REFUSE);

        $this->em->flush();
    }

    public function getPublicFilePath(KycFile $file): string
    {
        return $file->getFilePath();
    }
}
