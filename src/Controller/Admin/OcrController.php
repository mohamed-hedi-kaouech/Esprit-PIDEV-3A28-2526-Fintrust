<?php
namespace App\Controller\Admin;

use App\Service\OcrService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ocr', name: 'admin_ocr_')]
class OcrController extends AbstractController
{
    public function __construct(private OcrService $ocrService) {}

    #[Route('/extract', name: 'extract', methods: ['POST'])]
    public function extract(Request $request): JsonResponse
    {
        $file = $request->files->get('invoice');

        if (!$file) {
            return $this->json(['error' => 'Aucun fichier recu.'], 400);
        }

        // Validation type
        $allowedMimes = ['image/jpeg','image/png','image/gif','image/bmp','image/tiff','application/pdf'];
        $mime = $file->getMimeType();
        if (!in_array($mime, $allowedMimes, true)) {
            return $this->json(['error' => 'Format non supporte. Utilisez JPG, PNG ou PDF.'], 400);
        }

        // Validation taille (max 5 Mo)
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->json(['error' => 'Fichier trop volumineux (max 5 Mo).'], 400);
        }
        try {
            $text = $this->ocrService->extractText($file->getPathname(), $mime);

            if (empty($text)) {
                return $this->json(['error' => 'Aucun texte detecte dans ce fichier. Essayez une image plus nette.'], 422);
            }

            $data = $this->ocrService->parseInvoiceData($text);

            return $this->json([
                'success'      => true,
                'libelle'      => $data['libelle'],
                'montant'      => $data['montant'],
                'date'         => $data['date'],
                'categorie'    => $data['categorieSuggestion'],
                'quantite'     => $data['quantite'],
                'tva'          => $data['tva'],
                'prixUnitaire' => $data['prixUnitaire'],
                'rawText'      => $data['rawText'],
            ]);

        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur OCR : ' . $e->getMessage()], 500);
        }
    }
}