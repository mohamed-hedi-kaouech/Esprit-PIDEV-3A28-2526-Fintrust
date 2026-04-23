<?php

namespace App\Controller\Admin;

use App\Service\Notification\NotificationQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/notifications', name: 'admin_notifications_')]
class AdminNotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationQueryService $notificationQueryService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'type' => $request->query->get('type'),
            'severity' => $request->query->get('severity'),
            'read' => $request->query->get('read'),
            'date_from' => $request->query->get('date_from'),
            'date_to' => $request->query->get('date_to'),
            'wallet' => $request->query->get('wallet'),
            'category' => $request->query->get('category', 'admin'),
        ];
        $notifications = $this->notificationQueryService->findForAdmin($filters);

        return $this->render('admin/notifications/index.html.twig', [
            'notifications' => $notifications,
            'notificationItems' => $this->notificationQueryService->serializeList($notifications),
            'filters' => $filters,
            'unreadCount' => $this->notificationQueryService->countUnreadForAdmin($filters),
        ]);
    }
}
