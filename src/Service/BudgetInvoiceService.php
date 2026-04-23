<?php

namespace App\Service;

use App\Entity\User\User;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use TCPDF;

// Sous-classe pour personnaliser le pied de page
class FinTrustPdf extends TCPDF
{
    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'FinTrust - Gestion de Budget  |  Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

class BudgetInvoiceService
{
    public function __construct(
        private CategorieRepository $categorieRepository,
        private ItemRepository $itemRepository,
    ) {}

    public function generate(?User $user = null): string
    {
        $categories = $this->categorieRepository->findAll();
        $now        = new \DateTime();
        $invoiceNum = 'FT-' . $now->format('YmdHis');

        $totalBudget = 0.0;
        $totalSpent  = 0.0;
        $catsData    = [];

        foreach ($categories as $cat) {
            $items = $this->itemRepository->findBy(['categorie' => $cat]);
            $spent = 0.0;
            $rows  = [];
            foreach ($items as $item) {
                $spent += $item->getMontant();
                $rows[] = [
                    'libelle' => $item->getLibelle(),
                    'date'    => $item->getDateCreation()?->format('d/m/Y') ?? '-',
                    'montant' => $item->getMontant(),
                ];
            }
            $budget = $cat->getBudgetPrevu();
            $totalBudget += $budget;
            $totalSpent  += $spent;
            $catsData[] = [
                'nom'       => $cat->getNomCategorie(),
                'budget'    => $budget,
                'spent'     => $spent,
                'remaining' => $budget - $spent,
                'items'     => $rows,
            ];
        }

        $totalRemaining = $totalBudget - $totalSpent;

        // Init PDF
        $pdf = new FinTrustPdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('FinTrust');
        $pdf->SetTitle('Facture Budget - ' . $invoiceNum);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetFooterMargin(12);
        $pdf->SetAutoPageBreak(true, 22);
        $pdf->AddPage();

        // ── EN-TETE ──────────────────────────────────────────────────────────

        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetTextColor(21, 96, 189);
        $pdf->SetXY(20, 20);
        $pdf->Cell(90, 10, 'FinTrust', 0, 0, 'L');

        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetXY(110, 20);
        $pdf->Cell(80, 10, 'FACTURE', 0, 0, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY(20, 32);
        $pdf->Cell(90, 5, 'Plateforme de gestion financiere', 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(40, 40, 40);
        $pdf->SetXY(110, 32);
        $pdf->Cell(40, 5, 'N Facture :', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 5, $invoiceNum, 0, 0, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(110, 38);
        $pdf->Cell(40, 5, 'Date :', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 5, $now->format('d/m/Y'), 0, 0, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(110, 44);
        $pdf->Cell(40, 5, 'Heure :', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 5, $now->format('H:i'), 0, 0, 'R');

        $pdf->SetY(52);
        $pdf->SetDrawColor(21, 96, 189);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(5);

        if ($user !== null) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor(21, 96, 189);
            $pdf->Cell(0, 5, 'Etabli pour :', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->Cell(0, 5, $user->getFullName() . '  |  ' . $user->getEmail(), 0, 1, 'L');
            $pdf->Ln(3);
        }

        // ── RESUME BUDGET ─────────────────────────────────────────────────────

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFillColor(21, 96, 189);
        $pdf->Cell(170, 8, '  RESUME DU BUDGET', 0, 1, 'L', true);
        $pdf->Ln(1);

        $rows3 = [
            ['Budget Total Alloue',  number_format($totalBudget, 3, '.', ' ') . ' TND',   [235, 242, 255], [40, 40, 40]],
            ['Total Depense',        number_format($totalSpent, 3, '.', ' ') . ' TND',    [255, 255, 255], [200, 30, 30]],
            ['Montant Restant',      number_format($totalRemaining, 3, '.', ' ') . ' TND', [235, 242, 255], $totalRemaining >= 0 ? [22, 163, 74] : [200, 30, 30]],
        ];
        foreach ($rows3 as [$label, $value, $bg, $vc]) {
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->Cell(130, 7, '  ' . $label, 0, 0, 'L', true);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor($vc[0], $vc[1], $vc[2]);
            $pdf->Cell(40, 7, $value, 0, 1, 'R', true);
        }
        $pdf->Ln(5);

        // ── CATEGORIES ────────────────────────────────────────────────────────

        foreach ($catsData as $cat) {
            // Titre categorie
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFillColor(30, 41, 59);
            $pdf->Cell(170, 8, '  ' . strtoupper($cat['nom']) . '   |   Budget : ' . number_format($cat['budget'], 3, '.', ' ') . ' TND', 0, 1, 'L', true);

            if (empty($cat['items'])) {
                $pdf->SetFont('helvetica', 'I', 8);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->SetFillColor(250, 250, 250);
                $pdf->Cell(170, 6, '  Aucune depense enregistree.', 0, 1, 'L', true);
            } else {
                // En-tete colonnes
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFillColor(71, 85, 105);
                $pdf->Cell(10,  7, '#',        0, 0, 'C', true);
                $pdf->Cell(95,  7, 'Libelle',  0, 0, 'L', true);
                $pdf->Cell(35,  7, 'Date',     0, 0, 'C', true);
                $pdf->Cell(30,  7, 'Montant',  0, 0, 'R', true);
                $pdf->Ln();

                $pdf->SetFont('helvetica', '', 8.5);
                foreach ($cat['items'] as $idx => $item) {
                    $bg = ($idx % 2 === 0) ? [255, 255, 255] : [248, 250, 252];
                    $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
                    $pdf->SetTextColor(40, 40, 40);
                    $pdf->Cell(10,  7, $idx + 1,                                                    0, 0, 'C', true);
                    $pdf->Cell(95,  7, '  ' . $item['libelle'],                                     0, 0, 'L', true);
                    $pdf->Cell(35,  7, $item['date'],                                              0, 0, 'C', true);
                    $pdf->SetFont('helvetica', 'B', 8.5);
                    $pdf->Cell(30,  7, number_format($item['montant'], 3, '.', ' ') . ' TND',    0, 0, 'R', true);
                    $pdf->SetFont('helvetica', '', 8.5);
                    $pdf->Ln();
                }

                // Sous-total
                $pdf->SetFont('helvetica', 'B', 8.5);
                $pdf->SetFillColor(21, 96, 189);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(140, 7, 'Sous-total ' . $cat['nom'],                                  0, 0, 'R', true);
                $pdf->Cell(30,  7, number_format($cat['spent'], 3, '.', ' ') . ' TND',       0, 1, 'R', true);

                // Restant
                $rc = $cat['remaining'] >= 0 ? [22, 163, 74] : [200, 30, 30];
                $pdf->SetFillColor(235, 242, 255);
                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetTextColor(60, 60, 60);
                $pdf->Cell(140, 6, 'Restant sur cette categorie',                                  0, 0, 'R', true);
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetTextColor($rc[0], $rc[1], $rc[2]);
                $pdf->Cell(30, 6, number_format($cat['remaining'], 3, '.', ' ') . ' TND',    0, 1, 'R', true);
            }

            $pdf->Ln(4);
        }

        // ── TOTAL GENERAL ─────────────────────────────────────────────────────

        $pdf->SetDrawColor(21, 96, 189);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(4);

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(21, 96, 189);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(140, 9, '  TOTAL GENERAL DEPENSE',                              0, 0, 'L', true);
        $pdf->Cell(30,  9, number_format($totalSpent, 3, '.', ' ') . ' TND',   0, 1, 'R', true);
        $pdf->Ln(1);

        $rc2 = $totalRemaining >= 0 ? [22, 163, 74] : [200, 30, 30];
        $pdf->SetFillColor(235, 242, 255);
        $pdf->SetTextColor(21, 96, 189);
        $pdf->Cell(140, 9, '  BUDGET RESTANT',                                     0, 0, 'L', true);
        $pdf->SetTextColor($rc2[0], $rc2[1], $rc2[2]);
        $pdf->Cell(30,  9, number_format($totalRemaining, 3, '.', ' ') . ' TND', 0, 1, 'R', true);
        $pdf->Ln(10);

        $pdf->SetFont('helvetica', 'I', 7.5);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->MultiCell(170, 4, 'Ce document est genere automatiquement par la plateforme FinTrust. Montants en Dinars Tunisiens (TND).', 0, 'C');

        return $pdf->Output('', 'S');
    }
}
