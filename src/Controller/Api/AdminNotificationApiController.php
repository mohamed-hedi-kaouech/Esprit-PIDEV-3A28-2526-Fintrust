<?php

namespace App\Controller\Api;

use App\Service\Notification\NotificationQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/api/admin/notifications', name: 'api_admin_notifications_')]
class AdminNotificationApiController extends AbstractController
{
    public function __construct(
        private readonly NotificationQueryService $notificationQueryService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);
        $notifications = $this->notificationQueryService->findForAdmin($filters);

        return $this->json([
            'items' => $this->notificationQueryService->serializeList($notifications),
            'count' => count($notifications),
            'unread_count' => $this->notificationQueryService->countUnreadForAdmin($filters),
        ]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->json([
            'unread_count' => $this->notificationQueryService->countUnreadForAdmin($this->extractFilters($request)),
        ]);
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function read(int $id): JsonResponse
    {
        $notification = $this->notificationQueryService->find($id);
        if ($notification === null) {
            return $this->json(['error' => 'notification_not_found'], 404);
        }

        return $this->json([
            'success' => true,
            'updated' => $this->notificationQueryService->markAsRead($notification),
        ]);
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);
        $filters['read'] = false;
        $notifications = $this->notificationQueryService->findForAdmin($filters, 500);

        return $this->json([
            'success' => true,
            'updated' => $this->notificationQueryService->markAllAsRead($notifications),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        return [
            'type' => $request->query->get('type'),
            'severity' => $request->query->get('severity'),
            'read' => $request->query->get('read'),
            'date_from' => $request->query->get('date_from'),
            'date_to' => $request->query->get('date_to'),
            'wallet' => $request->query->get('wallet'),
            'category' => $request->query->get('category', 'admin'),
        ];
    }
}
