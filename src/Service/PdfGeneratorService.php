<?php

namespace App\Service;

use TCPDF;

class PdfGeneratorService
{
    public function generateCategorieInvoice(array $categories): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('FinTrust');
        $pdf->SetTitle('Facture des Catégories');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // Titre
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Facture des Catégories', 0, 1, 'C');
        $pdf->Ln(6);

        // En-tête tableau
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(15, 23, 42);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(60, 9, 'Catégorie', 1, 0, 'L', true);
        $pdf->Cell(60, 9, 'Budget', 1, 0, 'R', true);
        $pdf->Cell(60, 9, 'Items', 1, 1, 'L', true);

        // Lignes
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(15, 23, 42);
        foreach ($categories as $i => $categorie) {
            $bg = ($i % 2 === 0) ? [255, 255, 255] : [248, 250, 252];
            $pdf->SetFillColor(...$bg);
            $pdf->Cell(60, 8, $categorie['name'], 1, 0, 'L', true);
            $pdf->Cell(60, 8, number_format((float) $categorie['budget'], 2) . ' TND', 1, 0, 'R', true);
            $pdf->Cell(60, 8, implode(', ', $categorie['items']), 1, 1, 'L', true);
        }

        $filePath = sys_get_temp_dir() . '/facture_categories.pdf';
        $pdf->Output($filePath, 'F');

        return $filePath;
    }
}