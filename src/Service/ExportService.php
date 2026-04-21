<?php

namespace App\Service;

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
}
