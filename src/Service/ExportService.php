<?php

namespace App\Service;

use App\Entity\Publication\Publication;
use App\Entity\User\User;
use Nucleos\DompdfBundle\Wrapper\DompdfWrapperInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

/**
 * Service - Export des donnees utilisateurs.
 */
class ExportService
{
    public function __construct(
        private readonly DompdfWrapperInterface $pdfWrapper,
        private readonly Environment $twig,
    ) {}

    /**
     * Genere une reponse CSV pour une liste d'utilisateurs.
     * BOM UTF-8 inclus pour compatibilite Excel.
     *
     * @param User[] $users
     */
    public function exportUsersCsv(array $users): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($users) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID', 'Nom', 'Prenom', 'Email', 'Telephone',
                'Role', 'Statut', 'KYC', 'Inscrit le',
            ], ';');

            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->getId(),
                    $user->getNom(),
                    $user->getPrenom(),
                    $user->getEmail(),
                    $user->getNumTel() ?? '',
                    $user->getRole(),
                    $user->getStatus(),
                    $user->getKycStatus() ?? 'AUCUN',
                    $user->getCreatedAt()->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($handle);
        });

        $filename = 'fintrust_utilisateurs_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    /**
     * Genere un vrai fichier PDF avec NucleosDompdfBundle.
     *
     * @param User[] $users
     * @param array<string, mixed> $stats
     */
    public function exportUsersPdf(array $users, array $stats = []): Response
    {
        $html = $this->twig->render('admin/users/export_pdf.html.twig', [
            'users' => $users,
            'stats' => $stats,
            'generatedAt' => new \DateTimeImmutable(),
        ]);
        $filename = 'fintrust_utilisateurs_' . date('Ymd_His') . '.pdf';

        return new Response(
            $this->pdfWrapper->getPdf($html, ['isRemoteEnabled' => true]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Exporte la liste des publications en CSV.
     *
     * @param Publication[] $publications
     */
    public function exportPublicationsCsv(array $publications): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($publications) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Titre', 'Catégorie', 'Statut', 'Date de publication', 'Commentaires', 'Likes', 'Dislikes'], ';');

            foreach ($publications as $publication) {
                $commentCount = 0;
                $likeCount = 0;
                $dislikeCount = 0;

                foreach ($publication->getFeedbacks() as $feedback) {
                    $type = strtoupper((string) $feedback->getTypeReaction());
                    if ($type === 'LIKE') {
                        $likeCount++;
                    } elseif ($type === 'DISLIKE') {
                        $dislikeCount++;
                    }
                    if ($feedback->getCommentaire()) {
                        $commentCount++;
                    }
                }

                fputcsv($handle, [
                    $publication->getId(),
                    $publication->getTitre(),
                    $publication->getCategorie() ?: 'Non défini',
                    $publication->getStatut(),
                    $publication->getDatePublication()?->format('d/m/Y H:i') ?: '—',
                    $commentCount,
                    $likeCount,
                    $dislikeCount,
                ], ';');
            }

            fclose($handle);
        });

        $filename = 'fintrust_publications_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    /**
     * Exporte la liste des publications au format HTML imprimable.
     *
     * @param Publication[] $publications
     */
    public function exportPublicationsPdfHtml(array $publications): Response
    {
        $rows = '';

        foreach ($publications as $publication) {
            $commentCount = 0;
            $likeCount = 0;
            $dislikeCount = 0;

            foreach ($publication->getFeedbacks() as $feedback) {
                $type = strtoupper((string) $feedback->getTypeReaction());
                if ($type === 'LIKE') {
                    $likeCount++;
                } elseif ($type === 'DISLIKE') {
                    $dislikeCount++;
                }
                if ($feedback->getCommentaire()) {
                    $commentCount++;
                }
            }

            $rows .= sprintf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                $publication->getId(),
                htmlspecialchars((string) $publication->getTitre()),
                htmlspecialchars((string) ($publication->getCategorie() ?: 'Non défini')),
                htmlspecialchars((string) $publication->getStatut()),
                $publication->getDatePublication()?->format('d/m/Y H:i') ?: '—',
                $commentCount,
                $likeCount,
                $dislikeCount
            );
        }

        $date = (new \DateTime())->format('d/m/Y à H:i');
        $count = count($publications);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Export Publications — FinTrust</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; margin: 0; padding: 20px; }
  .header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; border-bottom: 2px solid #1560BD; padding-bottom: 12px; }
  .logo-box { width: 40px; height: 40px; background: #1560BD; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
  h1 { color: #1560BD; font-size: 18px; margin: 0; }
  .meta { color: #64748b; font-size: 11px; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; }
  thead th { background: #0f172a; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; }
  tbody td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
  tbody tr:nth-child(even) td { background: #f8fafc; }
  .print-btn { margin-bottom: 16px; padding: 8px 20px; background: #1560BD; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; }
  @media print { .print-btn { display: none; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨 Imprimer / Enregistrer en PDF</button>
<div class="header">
  <div class="logo-box">
    <svg viewBox="0 0 40 40" fill="none" width="28" height="28">
      <path d="M10 28V18l10-8 10 8v10H24v-6h-8v6H10Z" fill="white"/>
    </svg>
  </div>
  <div>
    <h1>FinTrust — Export Publications</h1>
  </div>
</div>
<div class="meta">Généré le {$date} — {$count} publication(s)</div>
<table>
  <thead>
    <tr><th>ID</th><th>Titre</th><th>Catégorie</th><th>Statut</th><th>Date</th><th>Commentaires</th><th>Likes</th><th>Dislikes</th></tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
