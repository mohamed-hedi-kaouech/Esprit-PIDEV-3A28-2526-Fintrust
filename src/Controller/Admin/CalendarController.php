<?php

namespace App\Controller\Admin;

use App\Service\FinTrustAdminReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/calendrier', name: 'admin_calendar_')]
class CalendarController extends AbstractController
{
    public function __construct(
        private readonly FinTrustAdminReportService $adminReportService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/calendar/index.html.twig');
    }

    #[Route('/export/rapport-pdf', name: 'export_report_pdf', methods: ['GET'])]
    public function exportReportPdf(): Response
    {
        return $this->adminReportService->exportGlobalReportPdf();
    }
}
