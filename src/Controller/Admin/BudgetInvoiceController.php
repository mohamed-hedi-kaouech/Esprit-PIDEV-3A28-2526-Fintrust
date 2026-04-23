<?php

namespace App\Controller\Admin;

use App\Service\BudgetInvoiceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API — Génération de la facture PDF de gestion de budget.
 *
 * GET /admin/budget/invoice/pdf
 *   → Retourne directement le fichier PDF téléchargeable.
 *
 * GET /admin/budget/invoice/preview
 *   → Affiche le PDF dans le navigateur (inline).
 */
#[Route('/admin/budget/invoice', name: 'admin_budget_invoice_')]
class BudgetInvoiceController extends AbstractController
{
    public function __construct(
        private BudgetInvoiceService $budgetInvoiceService,
    ) {}

    /**
     * Télécharge la facture PDF (Content-Disposition: attachment).
     */
    #[Route('/pdf', name: 'download', methods: ['GET'])]
    public function download(): Response
    {
        $pdfContent = $this->budgetInvoiceService->generate(
            $this->getUser() instanceof \App\Entity\User\User ? $this->getUser() : null
        );

        $filename = 'fintrust_budget_' . date('Ymd_His') . '.pdf';

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($pdfContent),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
        ]);
    }

    /**
     * Affiche la facture PDF dans le navigateur (Content-Disposition: inline).
     */
    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(): Response
    {
        $pdfContent = $this->budgetInvoiceService->generate(
            $this->getUser() instanceof \App\Entity\User\User ? $this->getUser() : null
        );

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="budget_preview.pdf"',
            'Content-Length'      => strlen($pdfContent),
        ]);
    }
}
