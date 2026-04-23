<?php

namespace App\Service;

use App\Entity\Publication\Publication;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Environment;

class PdfPublicationService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ContentAnalysisService $contentAnalysisService,
    ) {
    }

    public function generatePublicationPdf(Publication $publication): Response
    {
        $content = trim(strip_tags((string) $publication->getContenu()));
        $analysis = $this->buildUnavailableAnalysis();

        if ($content !== '') {
            try {
                $analysis = $this->contentAnalysisService->analyze($publication->getContenu() ?? '');
                $analysis['available'] = true;
            } catch (\Throwable) {
                $analysis = $this->buildUnavailableAnalysis();
            }
        }

        $html = $this->twig->render('admin/publication/pdf_publication.html.twig', [
            'publication' => $publication,
            'analysis' => $analysis,
            'generated_at' => new \DateTimeImmutable(),
            'analysis_available' => $analysis['available'],
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = $this->buildFilename($publication);

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * @return array{summary: string, key_points: list<string>, reading_time: string, simplified: string, available: bool}
     */
    private function buildUnavailableAnalysis(): array
    {
        return [
            'summary' => 'Resume intelligent non disponible pour cette publication.',
            'key_points' => [
                'Points cles non disponibles.',
            ],
            'reading_time' => 'Non estime',
            'simplified' => 'Version simplifiee non disponible.',
            'available' => false,
        ];
    }

    private function buildFilename(Publication $publication): string
    {
        $slugger = new AsciiSlugger('fr');
        $slug = strtolower((string) $slugger->slug($publication->getTitre() ?: 'publication'));

        return sprintf('publication_%d_%s.pdf', $publication->getId(), $slug);
    }
}
