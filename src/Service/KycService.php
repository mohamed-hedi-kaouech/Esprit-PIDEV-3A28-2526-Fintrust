<?php

namespace App\Service;

use App\Entity\User\Client\Kyc;
use App\Entity\User\Client\KycFile;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
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
    public function submitKyc(User $user, Kyc $kyc, array $uploadedFiles, string $signatureData): void
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

    public function approveKyc(Kyc $kyc, ?string $commentaire = null): void
    {
        $kyc->setStatut(Kyc::STATUT_APPROUVE);
        $kyc->setCommentaireAdmin($commentaire);

        $user = $kyc->getUser();
        $user->setKycStatus(User::KYC_APPROUVE);
        $user->setStatus(User::STATUS_ACTIF);

        $this->ensureWalletExistsForUser($user);

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

    public function synchronizeApprovedUserWallet(User $user, bool $flush = true): ?Wallet
    {
        if ($user->getKycStatus() !== User::KYC_APPROUVE) {
            return null;
        }

        $user->setStatus(User::STATUS_ACTIF);

        $wallet = $this->ensureWalletExistsForUser($user);

        if ($flush) {
            $this->em->flush();
        }

        return $wallet;
    }

    private function ensureWalletExistsForUser(User $user): Wallet
    {
        /** @var Wallet|null $existingWallet */
        $existingWallet = $this->em->getRepository(Wallet::class)->createQueryBuilder('w')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.dateCreation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingWallet instanceof Wallet) {
            if ($existingWallet->getUser() === null) {
                $existingWallet->setUser($user);
            }
            if ($existingWallet->getIdUser() !== $user->getId()) {
                $existingWallet->setIdUser($user->getId());
            }

            return $existingWallet;
        }

        $wallet = new Wallet();
        $wallet
            ->setUser($user)
            ->setIdUser($user->getId())
            ->setNomProprietaire($user->getFullName())
            ->setTelephone($user->getNumTel())
            ->setEmail($user->getEmail())
            ->setCodeAcces(str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))
            ->setEstActif(true)
            ->setSolde('0.00')
            ->setPlafondDecouvert('0.00')
            ->setDevise('TND')
            ->setStatut('actif')
            ->setDateCreation(new \DateTime())
            ->setTentativesEchouees(0)
            ->setDateDerniereTentative(null)
            ->setEstBloque(false);

        $this->em->persist($wallet);

        return $wallet;
    }
}
