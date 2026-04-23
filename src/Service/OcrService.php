<?php
namespace App\Service;

class OcrService
{
    private const OCR_API_URL = 'https://api.ocr.space/parse/image';
    private const API_KEY     = 'helloworld';

    public function __construct() {}

    public function extractText(string $filePath, string $mimeType): string
    {
        $fileToSend = $filePath;
        $tempFile   = null;
        if (in_array($mimeType, ['image/jpeg','image/png','image/gif','image/bmp'], true)
            && extension_loaded('gd') && function_exists('imagecreatefromjpeg')) {
            $tempFile   = $this->forceCompress($filePath, $mimeType);
            $fileToSend = $tempFile;
        } elseif (filesize($filePath) > 1000 * 1024) {
            throw new \RuntimeException('Image trop grande. Utilisez une image < 1 Mo.');
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
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
                'file'              => new \CURLFile($fileToSend, 'image/jpeg', 'invoice.jpg'),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($tempFile && file_exists($tempFile)) @unlink($tempFile);
        if ($err) throw new \RuntimeException('Erreur reseau : ' . $err);
        $data = json_decode($raw, true);
        if (!empty($data['IsErroredOnProcessing'])) throw new \RuntimeException('OCR error: ' . ($data['ErrorMessage'][0] ?? 'Unknown'));
        $text = '';
        foreach ($data['ParsedResults'] ?? [] as $r) $text .= $r['ParsedText'] ?? '';
        return trim($text);
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