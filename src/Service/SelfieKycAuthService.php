<?php

namespace App\Service;

use App\Entity\User\Client\Kyc;
use App\Entity\User\Client\KycFile;
use App\Entity\User\User;
use App\Repository\KycRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SelfieKycAuthService
{
    private const REFERENCE_PREFIX = 'selfie_reference_';
    private const MIN_SIMILARITY_SCORE = 74;
    private const MAX_DESCRIPTOR_DISTANCE = 0.46;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KycRepository $kycRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function hasSelfieReference(User $user): bool
    {
        $kyc = $this->kycRepository->findLatestByUser($user);

        return $kyc instanceof Kyc && $this->findReferenceSelfieFile($kyc) instanceof KycFile;
    }

    /**
     * @return array{message:string, filePath:string}
     */
    public function storeReferenceSelfie(User $user, string $selfieData, string $fingerprintData): array
    {
        $kyc = $this->kycRepository->findLatestByUser($user);
        if (!$kyc instanceof Kyc) {
            throw new \RuntimeException('Deposez d abord un dossier KYC avant d enregistrer votre selfie de reference.');
        }

        $binary = $this->decodeBase64Image($selfieData);
        $fingerprint = $this->decodeFingerprint($fingerprintData);

        $this->removePreviousReferenceSelfies($kyc);

        $filename = self::REFERENCE_PREFIX . bin2hex(random_bytes(8)) . '.png';
        $relativePath = 'uploads/kyc-files/' . $filename;
        $absolutePath = $this->projectDir . '/public/' . $relativePath;

        $directory = \dirname($absolutePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $binary);
        $this->storeFingerprintFile($absolutePath, $fingerprint);

        $kycFile = new KycFile();
        $kycFile->setFileName($filename);
        $kycFile->setFilePath($relativePath);
        $kycFile->setFileType('image/png');
        $kycFile->setFileSize(strlen($binary));
        $kycFile->setUpdatedAt(new \DateTime());
        $kyc->addFile($kycFile);

        $this->em->persist($kycFile);
        $this->em->flush();

        return [
            'message' => 'Selfie KYC enregistre avec succes. Vous pouvez maintenant utiliser la connexion selfie KYC sur ce compte.',
            'filePath' => $relativePath,
        ];
    }

    /**
     * @return array{matched:bool, score:int, threshold:int, message:string}
     */
    public function verifySelfie(User $user, string $fingerprintData): array
    {
        $kyc = $this->kycRepository->findLatestByUser($user);
        if (!$kyc instanceof Kyc) {
            throw new \RuntimeException('Aucun dossier KYC n a ete trouve pour ce compte.');
        }

        $reference = $this->findReferenceSelfieFile($kyc);
        if (!$reference instanceof KycFile) {
            throw new \RuntimeException('Aucun selfie KYC de reference n est disponible pour ce compte.');
        }

        $absoluteReferencePath = $this->projectDir . '/public/' . ltrim($reference->getFilePath(), '/');
        if (!is_file($absoluteReferencePath)) {
            throw new \RuntimeException('Le selfie KYC de reference est introuvable sur le serveur.');
        }

        $referenceFingerprint = $this->loadFingerprintFile($absoluteReferencePath);
        $candidateFingerprint = $this->decodeFingerprint($fingerprintData);
        $score = $this->calculateSimilarityScore($referenceFingerprint, $candidateFingerprint);
        $matched = $score >= self::MIN_SIMILARITY_SCORE;

        return [
            'matched' => $matched,
            'score' => $score,
            'threshold' => self::MIN_SIMILARITY_SCORE,
            'message' => $matched
                ? 'Selfie KYC reconnu. Votre identite visuelle correspond au dossier enregistre.'
                : 'Le selfie capture ne correspond pas suffisamment au selfie KYC de reference de ce compte.',
        ];
    }

    private function removePreviousReferenceSelfies(Kyc $kyc): void
    {
        foreach ($kyc->getFiles() as $file) {
            if (!$file instanceof KycFile || !$this->isReferenceSelfie($file)) {
                continue;
            }

            $absolutePath = $this->projectDir . '/public/' . ltrim($file->getFilePath(), '/');
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            $fingerprintPath = $this->getFingerprintPath($absolutePath);
            if (is_file($fingerprintPath)) {
                @unlink($fingerprintPath);
            }

            $kyc->removeFile($file);
            $this->em->remove($file);
        }
    }

    private function findReferenceSelfieFile(Kyc $kyc): ?KycFile
    {
        foreach ($kyc->getFiles() as $file) {
            if ($file instanceof KycFile && $this->isReferenceSelfie($file)) {
                return $file;
            }
        }

        return null;
    }

    private function isReferenceSelfie(KycFile $file): bool
    {
        return str_starts_with(strtolower($file->getFileName()), self::REFERENCE_PREFIX);
    }

    private function decodeBase64Image(string $imageData): string
    {
        if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $imageData)) {
            throw new \RuntimeException('Le selfie capture est invalide. Reprenez une photo depuis la camera.');
        }

        $binary = base64_decode(substr($imageData, strpos($imageData, ',') + 1), true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Le selfie capture n a pas pu etre decode.');
        }

        return $binary;
    }

    /**
     * @return array{
     *   hash:string,
     *   upperHash:string,
     *   middleHash:string,
     *   lowerHash:string,
     *   descriptor:array<int,float>,
     *   histogram:array<int,float>,
     *   mean:float,
     *   deviation:float
     * }
     */
    private function decodeFingerprint(string $fingerprintData): array
    {
        $decoded = json_decode($fingerprintData, true);

        if (
            !is_array($decoded)
            || !isset($decoded['hash'], $decoded['histogram'], $decoded['mean'])
            || !is_array($decoded['histogram'])
        ) {
            throw new \RuntimeException('L empreinte selfie est invalide. Reprenez une capture plus nette.');
        }

        $globalHash = (string) $decoded['hash'];
        $histogram = array_map(static fn ($value) => (float) $value, array_values($decoded['histogram']));
        $descriptor = [];

        if (isset($decoded['descriptor']) && is_array($decoded['descriptor'])) {
            $descriptor = array_values(array_map(static fn ($value) => (float) $value, $decoded['descriptor']));
        }

        $upperHash = isset($decoded['upperHash']) ? (string) $decoded['upperHash'] : substr($globalHash, 0, (int) floor(strlen($globalHash) * 0.32));
        $middleHash = isset($decoded['middleHash']) ? (string) $decoded['middleHash'] : substr($globalHash, (int) floor(strlen($globalHash) * 0.32), (int) floor(strlen($globalHash) * 0.36));
        $lowerHash = isset($decoded['lowerHash']) ? (string) $decoded['lowerHash'] : substr($globalHash, (int) floor(strlen($globalHash) * 0.68));

        if ($upperHash === '') {
            $upperHash = $globalHash;
        }

        if ($middleHash === '') {
            $middleHash = $globalHash;
        }

        if ($lowerHash === '') {
            $lowerHash = $globalHash;
        }

        return [
            'hash' => $globalHash,
            'upperHash' => $upperHash,
            'middleHash' => $middleHash,
            'lowerHash' => $lowerHash,
            'descriptor' => $descriptor,
            'histogram' => $histogram,
            'mean' => (float) $decoded['mean'],
            'deviation' => isset($decoded['deviation']) ? (float) $decoded['deviation'] : 32.0,
        ];
    }

    /**
     * @param array{
     *   hash:string,
     *   upperHash:string,
     *   middleHash:string,
     *   lowerHash:string,
     *   descriptor:array<int,float>,
     *   histogram:array<int,float>,
     *   mean:float,
     *   deviation:float
     * } $fingerprint
     */
    private function storeFingerprintFile(string $absoluteImagePath, array $fingerprint): void
    {
        file_put_contents($this->getFingerprintPath($absoluteImagePath), json_encode($fingerprint, JSON_PRETTY_PRINT));
    }

    /**
     * @return array{
     *   hash:string,
     *   upperHash:string,
     *   middleHash:string,
     *   lowerHash:string,
     *   descriptor:array<int,float>,
     *   histogram:array<int,float>,
     *   mean:float,
     *   deviation:float
     * }
     */
    private function loadFingerprintFile(string $absoluteImagePath): array
    {
        $fingerprintPath = $this->getFingerprintPath($absoluteImagePath);
        if (!is_file($fingerprintPath)) {
            throw new \RuntimeException('L empreinte du selfie KYC de reference est absente. Reprenez un selfie de reference depuis votre dossier KYC.');
        }

        $content = (string) file_get_contents($fingerprintPath);

        return $this->decodeFingerprint($content);
    }

    private function getFingerprintPath(string $absoluteImagePath): string
    {
        return $absoluteImagePath . '.json';
    }

    /**
     * @param array{
     *   hash:string,
     *   upperHash:string,
     *   middleHash:string,
     *   lowerHash:string,
     *   descriptor:array<int,float>,
     *   histogram:array<int,float>,
     *   mean:float,
     *   deviation:float
     * } $reference
     * @param array{
     *   hash:string,
     *   upperHash:string,
     *   middleHash:string,
     *   lowerHash:string,
     *   descriptor:array<int,float>,
     *   histogram:array<int,float>,
     *   mean:float,
     *   deviation:float
     * } $candidate
     */
    private function calculateSimilarityScore(array $reference, array $candidate): int
    {
        if ($reference['descriptor'] !== [] && $candidate['descriptor'] !== []) {
            $distance = $this->calculateDescriptorDistance($reference['descriptor'], $candidate['descriptor']);
            $score = (1 - min(1, $distance)) * 100;

            if ($distance > self::MAX_DESCRIPTOR_DISTANCE) {
                $score -= (($distance - self::MAX_DESCRIPTOR_DISTANCE) * 140);
            }

            return (int) max(0, min(100, round($score)));
        }

        $hashSimilarity = $this->calculateHashSimilarity($reference['hash'], $candidate['hash']);
        $upperSimilarity = $this->calculateHashSimilarity($reference['upperHash'], $candidate['upperHash']);
        $middleSimilarity = $this->calculateHashSimilarity($reference['middleHash'], $candidate['middleHash']);
        $lowerSimilarity = $this->calculateHashSimilarity($reference['lowerHash'], $candidate['lowerHash']);

        $histogramSimilarity = 0.0;
        $referenceHistogram = $reference['histogram'];
        $candidateHistogram = $candidate['histogram'];
        $bucketCount = min(count($referenceHistogram), count($candidateHistogram));

        for ($index = 0; $index < $bucketCount; $index++) {
            $histogramSimilarity += min($referenceHistogram[$index], $candidateHistogram[$index]);
        }

        $brightnessSimilarity = 1 - (abs($reference['mean'] - $candidate['mean']) / 255);
        $contrastSimilarity = 1 - (abs($reference['deviation'] - $candidate['deviation']) / max(1, max($reference['deviation'], $candidate['deviation'])));

        $score = (
            ($hashSimilarity * 0.16) +
            ($upperSimilarity * 0.16) +
            ($middleSimilarity * 0.28) +
            ($lowerSimilarity * 0.14) +
            ($histogramSimilarity * 0.16) +
            ($brightnessSimilarity * 0.05) +
            ($contrastSimilarity * 0.05)
        ) * 100;

        $hardMismatch =
            $middleSimilarity < 0.68
            || $upperSimilarity < 0.56
            || $lowerSimilarity < 0.52
            || $hashSimilarity < 0.60;

        if ($hardMismatch) {
            $score -= 18;
        }

        return (int) max(0, min(100, round($score)));
    }

    private function calculateHashSimilarity(string $referenceHash, string $candidateHash): float
    {
        $hashLength = min(strlen($referenceHash), strlen($candidateHash));
        $hammingDistance = 0;

        for ($index = 0; $index < $hashLength; $index++) {
            if (($referenceHash[$index] ?? '0') !== ($candidateHash[$index] ?? '0')) {
                $hammingDistance++;
            }
        }

        return 1 - ($hammingDistance / max(1, $hashLength));
    }

    /**
     * @param array<int,float> $referenceDescriptor
     * @param array<int,float> $candidateDescriptor
     */
    private function calculateDescriptorDistance(array $referenceDescriptor, array $candidateDescriptor): float
    {
        $length = min(count($referenceDescriptor), count($candidateDescriptor));
        if ($length === 0) {
            return 1.0;
        }

        $sum = 0.0;
        for ($index = 0; $index < $length; $index++) {
            $diff = $referenceDescriptor[$index] - $candidateDescriptor[$index];
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }
}
