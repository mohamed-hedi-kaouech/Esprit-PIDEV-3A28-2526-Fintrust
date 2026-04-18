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
    private const NORMALIZED_SIZE = 48;
    private const HASH_SIZE = 16;
    private const MIN_SIMILARITY_SCORE = 72;

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
    public function storeReferenceSelfie(User $user, string $selfieData): array
    {
        $kyc = $this->kycRepository->findLatestByUser($user);
        if (!$kyc instanceof Kyc) {
            throw new \RuntimeException('Deposez d abord un dossier KYC avant d enregistrer votre selfie de reference.');
        }

        $binary = $this->decodeBase64Image($selfieData);
        $this->assertImageEngineAvailable();
        $this->createFingerprint($binary);

        $this->removePreviousReferenceSelfies($kyc);

        $filename = self::REFERENCE_PREFIX . bin2hex(random_bytes(8)) . '.png';
        $relativePath = 'uploads/kyc-files/' . $filename;
        $absolutePath = $this->projectDir . '/public/' . $relativePath;

        $directory = \dirname($absolutePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $binary);

        $kycFile = new KycFile();
        $kycFile->setFileName($filename);
        $kycFile->setFilePath($relativePath);
        $kycFile->setFileType('image/png');
        $kycFile->setFileSize(strlen($binary));
        $kycFile->setUpdatedAt(new \DateTimeImmutable());
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
    public function verifySelfie(User $user, string $selfieData): array
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

        $this->assertImageEngineAvailable();

        $referenceFingerprint = $this->createFingerprint((string) file_get_contents($absoluteReferencePath));
        $candidateFingerprint = $this->createFingerprint($this->decodeBase64Image($selfieData));
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

    private function assertImageEngineAvailable(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Le traitement selfie requiert l extension GD de PHP sur ce serveur.');
        }
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
     * @return array{hash:string, histogram:array<int,float>, mean:float, pixels:array<int,int>}
     */
    private function createFingerprint(string $binary): array
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new \RuntimeException('Le selfie capture n est pas exploitable. Utilisez une image plus nette.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $square = min($sourceWidth, $sourceHeight);
        $srcX = (int) floor(($sourceWidth - $square) / 2);
        $srcY = (int) floor(($sourceHeight - $square) / 2);

        $normalized = imagecreatetruecolor(self::NORMALIZED_SIZE, self::NORMALIZED_SIZE);
        imagecopyresampled(
            $normalized,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::NORMALIZED_SIZE,
            self::NORMALIZED_SIZE,
            $square,
            $square
        );

        $pixels = [];
        $sum = 0.0;
        $histogram = array_fill(0, 16, 0.0);

        for ($y = 0; $y < self::NORMALIZED_SIZE; $y++) {
            for ($x = 0; $x < self::NORMALIZED_SIZE; $x++) {
                $rgb = imagecolorat($normalized, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $gray = (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));

                $pixels[] = $gray;
                $sum += $gray;
                $histogram[(int) floor($gray / 16)]++;
            }
        }

        imagedestroy($normalized);
        imagedestroy($source);

        $mean = $sum / count($pixels);
        $hash = '';
        $step = (int) (self::NORMALIZED_SIZE / self::HASH_SIZE);

        for ($gridY = 0; $gridY < self::HASH_SIZE; $gridY++) {
            for ($gridX = 0; $gridX < self::HASH_SIZE; $gridX++) {
                $cellSum = 0;
                $cellCount = 0;

                for ($y = $gridY * $step; $y < ($gridY + 1) * $step; $y++) {
                    for ($x = $gridX * $step; $x < ($gridX + 1) * $step; $x++) {
                        $index = ($y * self::NORMALIZED_SIZE) + $x;
                        $cellSum += $pixels[$index];
                        $cellCount++;
                    }
                }

                $cellAverage = $cellCount > 0 ? ($cellSum / $cellCount) : 0;
                $hash .= $cellAverage >= $mean ? '1' : '0';
            }
        }

        $totalPixels = (float) count($pixels);
        foreach ($histogram as $index => $value) {
            $histogram[$index] = $value / $totalPixels;
        }

        return [
            'hash' => $hash,
            'histogram' => $histogram,
            'mean' => $mean,
            'pixels' => $pixels,
        ];
    }

    /**
     * @param array{hash:string, histogram:array<int,float>, mean:float, pixels:array<int,int>} $reference
     * @param array{hash:string, histogram:array<int,float>, mean:float, pixels:array<int,int>} $candidate
     */
    private function calculateSimilarityScore(array $reference, array $candidate): int
    {
        $hashLength = strlen($reference['hash']);
        $hammingDistance = 0;

        for ($index = 0; $index < $hashLength; $index++) {
            if (($reference['hash'][$index] ?? '0') !== ($candidate['hash'][$index] ?? '0')) {
                $hammingDistance++;
            }
        }

        $hashSimilarity = 1 - ($hammingDistance / max(1, $hashLength));

        $histogramSimilarity = 0.0;
        foreach ($reference['histogram'] as $index => $value) {
            $histogramSimilarity += min($value, $candidate['histogram'][$index] ?? 0.0);
        }

        $pixelDifference = 0.0;
        $pixelCount = min(count($reference['pixels']), count($candidate['pixels']));
        for ($index = 0; $index < $pixelCount; $index++) {
            $pixelDifference += abs($reference['pixels'][$index] - $candidate['pixels'][$index]);
        }

        $pixelSimilarity = 1 - (($pixelDifference / max(1, $pixelCount)) / 255);
        $brightnessSimilarity = 1 - (abs($reference['mean'] - $candidate['mean']) / 255);

        $score = (
            ($hashSimilarity * 0.42) +
            ($pixelSimilarity * 0.33) +
            ($histogramSimilarity * 0.17) +
            ($brightnessSimilarity * 0.08)
        ) * 100;

        return (int) max(0, min(100, round($score)));
    }
}
