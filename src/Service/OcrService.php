<?php
namespace App\Service;

class OcrService
{
    private const OCR_API_URL = 'https://api.ocr.space/parse/image';
    private const API_KEY     = 'helloworld';
    private const SUPPORTED_MIME_MAP = [
        'application/pdf' => ['pdf', 'application/pdf'],
        'image/jpeg' => ['jpg', 'image/jpeg'],
        'image/jpg' => ['jpg', 'image/jpeg'],
        'image/png' => ['png', 'image/png'],
        'image/gif' => ['gif', 'image/gif'],
        'image/bmp' => ['bmp', 'image/bmp'],
        'image/x-ms-bmp' => ['bmp', 'image/bmp'],
        'image/tif' => ['tif', 'image/tiff'],
        'image/tiff' => ['tiff', 'image/tiff'],
        'image/webp' => ['webp', 'image/webp'],
    ];

    public function __construct() {}

    public function extractText(string $filePath, string $mimeType, ?string $originalName = null): string
    {
        $fileToSend = $filePath;
        $tempFile   = null;
        [$uploadName, $uploadMime] = $this->buildUploadMetadata($mimeType, $originalName);

        if (in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'image/x-ms-bmp'], true)
            && extension_loaded('gd') && function_exists('imagecreatefromjpeg')) {
            $tempFile   = $this->forceCompress($filePath, $mimeType);
            $fileToSend = $tempFile;
            $uploadMime = 'image/jpeg';
            $uploadName = 'invoice.jpg';
        } elseif (filesize($filePath) > 5 * 1024 * 1024) {
            throw new \RuntimeException('Fichier trop grand. Utilisez un fichier de 5 Mo maximum.');
        }

        try {
            $raw = $this->sendOcrRequest($fileToSend, $uploadMime, $uploadName, true);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
            $isCertificateError = str_contains($message, 'SSL certificate problem')
                || str_contains($message, 'unable to get local issuer certificate')
                || str_contains($message, 'schannel')
                || str_contains($message, 'certificate');

            if (!$isCertificateError) {
                if ($tempFile && file_exists($tempFile)) {
                    @unlink($tempFile);
                }

                throw $exception;
            }

            $raw = $this->sendOcrRequest($fileToSend, $uploadMime, $uploadName, false);
        }

        if ($tempFile && file_exists($tempFile)) @unlink($tempFile);
        $data = json_decode($raw, true);
        if (!empty($data['IsErroredOnProcessing'])) throw new \RuntimeException('OCR error: ' . ($data['ErrorMessage'][0] ?? 'Unknown'));
        $text = '';
        foreach ($data['ParsedResults'] ?? [] as $r) $text .= $r['ParsedText'] ?? '';
        return trim($text);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildUploadMetadata(string $mimeType, ?string $originalName): array
    {
        $meta = self::SUPPORTED_MIME_MAP[$mimeType] ?? null;
        if ($meta === null) {
            $guessedExtension = pathinfo((string) $originalName, PATHINFO_EXTENSION);
            $extension = $guessedExtension !== '' ? strtolower($guessedExtension) : 'bin';

            return ['invoice.' . $extension, $mimeType];
        }

        [$defaultExtension, $normalizedMime] = $meta;
        $safeName = $originalName !== null ? trim($originalName) : '';

        if ($safeName === '') {
            return ['invoice.' . $defaultExtension, $normalizedMime];
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $safeName) ?: 'invoice';
        if (!str_contains($safeName, '.')) {
            $safeName .= '.' . $defaultExtension;
        }

        $extension = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $safeName .= '.' . $defaultExtension;
        }

        return [$safeName, $normalizedMime];
    }

    private function forceCompress(string $filePath, string $mimeType): string
    {
        $image = match($mimeType) {
            'image/png' => @imagecreatefrompng($filePath),
            'image/gif' => @imagecreatefromgif($filePath),
            default     => @imagecreatefromjpeg($filePath),
        };
        if (!$image) throw new \RuntimeException('Impossible de lire l image.');
        $w = imagesx($image); $h = imagesy($image);
        if ($w > 1000) {
            $nw=1000; $nh=(int)($h*(1000/$w));
            $r=imagecreatetruecolor($nw,$nh);
            imagefill($r,0,0,imagecolorallocate($r,255,255,255));
            imagecopyresampled($r,$image,0,0,0,0,$nw,$nh,$w,$h);
            imagedestroy($image); $image=$r;
        }
        $temp=tempnam(sys_get_temp_dir(),'ocr_').'.jpg';
        foreach ([70,55,40,25,15] as $q) {
            imagejpeg($image,$temp,$q);
            if (filesize($temp)<=900*1024) break;
            $cw=imagesx($image); $ch=imagesy($image);
            $nw=(int)($cw*0.7); $nh=(int)($ch*0.7);
            if ($nw<300) break;
            $s=imagecreatetruecolor($nw,$nh);
            imagefill($s,0,0,imagecolorallocate($s,255,255,255));
            imagecopyresampled($s,$image,0,0,0,0,$nw,$nh,$cw,$ch);
            imagedestroy($image); $image=$s;
        }
        imagedestroy($image);
        return $temp;
    }

    private function sendOcrRequest(string $filePath, string $mimeType, string $uploadName, bool $verifySsl): string
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL            => self::OCR_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'apikey'            => self::API_KEY,
                'language'          => 'fre',
                'isOverlayRequired' => 'false',
                'detectOrientation' => 'true',
                'scale'             => 'true',
                'OCREngine'         => '2',
                'file'              => new \CURLFile($filePath, $mimeType, $uploadName),
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ];

        $caFile = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($caFile) && $caFile !== '' && is_file($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('Erreur reseau : ' . $err);
        }

        if ($raw === false || $raw === '' || $httpCode >= 400) {
            throw new \RuntimeException('Service OCR indisponible (HTTP ' . $httpCode . ').');
        }

        return $raw;
    }

    public function parseInvoiceData(string $text): array
    {
        $result = ['libelle'=>'','montant'=>'','date'=>'','categorieSuggestion'=>'','quantite'=>'1','tva'=>'0','prixUnitaire'=>'','rawText'=>$text];

        // Normaliser : remplacer \r\n et \r par \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // ── LIBELLE : texte entre DESIGNATION et QTE ──────────────────────
        // Format OCR : "DESIGNATION\nEau Minérale Safia 1.5L\nQTÉ\n6\n..."
        // ou sur une ligne : "DESIGNATION Eau Minérale Safia 1.5L QTÉ 6"
        if (preg_match('/d[eé]signation\s+(.+?)\s+qt[eé]/isu', $text, $m)) {
            $result['libelle'] = ucfirst(mb_strtolower(trim($m[1])));
        } elseif (preg_match('/d[eé]signation[\s\n]+(.+?)[\s\n]+qt[eé]/isu', $text, $m)) {
            $result['libelle'] = ucfirst(mb_strtolower(trim($m[1])));
        }

        // ── QTE : nombre entre QTE et PRIX ───────────────────────────────
        // Format : "QTÉ\n6\nPRIX" ou "QTÉ 6 PRIX"
        if (preg_match('/qt[eé]\.?\s*[\n\r]*\s*([0-9]+(?:[.,][0-9]+)?)\s*[\n\r]*\s*(?:prix|unit|0,|[0-9])/isu', $text, $m)) {
            $result['quantite'] = str_replace(',', '.', $m[1]);
        } elseif (preg_match('/qt[eé]\.?\s+([0-9]+(?:[.,][0-9]+)?)/isu', $text, $m)) {
            $result['quantite'] = str_replace(',', '.', $m[1]);
        }

        // ── PRIX UNITAIRE : entre QTE et TOTAL ───────────────────────────
        // Format : "QTÉ\n6\nPRIX UNIT.\n0,650\nTOTAL"
        if (preg_match('/prix\s+unit\.?\s*[\n\r]*\s*([0-9]+[.,][0-9]{1,3})/isu', $text, $m)) {
            $result['prixUnitaire'] = str_replace(',', '.', $m[1]);
        } elseif (preg_match('/unit\.?\s*[\n\r]*\s*([0-9]+[.,][0-9]{1,3})/isu', $text, $m)) {
            $result['prixUnitaire'] = str_replace(',', '.', $m[1]);
        }

        // ── MONTANT : TOTAL TTC ───────────────────────────────────────────
        if (preg_match('/total\s+ttc\s+([0-9]+[.,][0-9]{1,3})/isu', $text, $m)) {
            $result['montant'] = str_replace(',', '.', $m[1]);
        } elseif (preg_match('/([0-9]+[.,][0-9]{1,3})\s*dt\b/isu', $text, $m)) {
            $result['montant'] = str_replace(',', '.', $m[1]);
        }

        // ── DATE ──────────────────────────────────────────────────────────
        if (preg_match('/date\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/isu', $text, $m)) {
            $result['date'] = $m[1];
        } elseif (preg_match('/(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})/', $text, $m)) {
            $result['date'] = $m[1];
        }

        // ── TVA : TVA (19%) ───────────────────────────────────────────────
        if (preg_match('/tva\s*\(\s*([0-9]+(?:[.,][0-9]+)?)\s*%\s*\)/isu', $text, $m)) {
            $result['tva'] = str_replace(',', '.', $m[1]);
        } elseif (preg_match('/tva\s*[:\-]?\s*([0-9]+(?:[.,][0-9]+)?)\s*%/isu', $text, $m)) {
            $result['tva'] = str_replace(',', '.', $m[1]);
        }

        // ── CATEGORIE ─────────────────────────────────────────────────────
        $tl = mb_strtolower($text);
        $map = [
            'alimentation' => ['carrefour','restaurant','repas','nourriture','epicerie','supermarche','cafe','pizza','burger','eau minerale','alimentaire'],
            'transport'    => ['taxi','uber','carburant','essence','billet','train','avion','bus','metro','parking'],
            'sante'        => ['pharmacie','medecin','clinique','hopital','medicament','consultation','dentiste'],
            'logement'     => ['loyer','electricite','gaz','internet','telephone','assurance','charges'],
            'loisirs'      => ['cinema','theatre','sport','abonnement','netflix','spotify','jeux','concert'],
            'education'    => ['formation','cours','livre','ecole','universite','certification'],
            'informatique' => ['ordinateur','logiciel','materiel','serveur','cloud','hebergement'],
        ];
        foreach ($map as $cat => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($tl, $kw)) { $result['categorieSuggestion']=$cat; break 2; }
            }
        }

        return $result;
    }
}
