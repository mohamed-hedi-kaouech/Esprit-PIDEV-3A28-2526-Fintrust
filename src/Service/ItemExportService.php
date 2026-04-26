<?php

namespace App\Service;

use App\Entity\Categorie\Item;
use Nucleos\DompdfBundle\Wrapper\DompdfWrapperInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class ItemExportService
{
    public function __construct(
        private readonly DompdfWrapperInterface $pdfWrapper,
        private readonly Environment $twig,
    ) {}

    /**
     * @param Item[] $items
     * @param array<string, mixed> $filters
     */
    public function exportItemsPdf(array $items, array $filters = []): Response
    {
        $grouped = [];
        $totalAmount = 0.0;
        $totalQuantity = 0.0;
        $averageAmount = 0.0;
        $highestItem = null;

        foreach ($items as $item) {
            $category = $item->getCategorie();
            $categoryName = $category->getNomCategorie();
            $amount = (float) $item->getMontant();
            $quantity = (float) ($item->getQuantite() ?? 1);

            if (!isset($grouped[$categoryName])) {
                $grouped[$categoryName] = [
                    'category' => $category,
                    'items' => [],
                    'total' => 0.0,
                ];
            }

            $grouped[$categoryName]['items'][] = $item;
            $grouped[$categoryName]['total'] += $amount;

            $totalAmount += $amount;
            $totalQuantity += $quantity;

            if ($highestItem === null || $amount > $highestItem->getMontant()) {
                $highestItem = $item;
            }
        }

        if (count($items) > 0) {
            $averageAmount = $totalAmount / count($items);
        }

        uasort($grouped, static fn (array $left, array $right): int => $right['total'] <=> $left['total']);

        $html = $this->twig->render('admin/item/export_pdf.html.twig', [
            'items' => $items,
            'groupedItems' => $grouped,
            'filters' => $filters,
            'generatedAt' => new \DateTimeImmutable(),
            'stats' => [
                'count' => count($items),
                'categories' => count($grouped),
                'totalAmount' => $totalAmount,
                'averageAmount' => $averageAmount,
                'totalQuantity' => $totalQuantity,
                'highestItem' => $highestItem,
            ],
        ]);

        $filename = 'fintrust_items_' . date('Ymd_His') . '.pdf';

        return new Response(
            $this->pdfWrapper->getPdf($html, [
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]
        );
    }
}
