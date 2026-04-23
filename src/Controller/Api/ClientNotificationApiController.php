<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Service\Notification\NotificationQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/notifications', name: 'api_notifications_')]
class ClientNotificationApiController extends AbstractController
{
    public function __construct(
        private readonly NotificationQueryService $notificationQueryService,
    ) {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $filters = $this->extractFilters($request);

        $notifications = $this->notificationQueryService->findForUser($user, $filters);

        return $this->json([
            'items' => $this->notificationQueryService->serializeList($notifications),
            'count' => count($notifications),
            'unread_count' => $this->notificationQueryService->countUnreadForUser($user, $filters),
        ]);
    }

    #[Route('/me/unread-count', name: 'me_unread_count', methods: ['GET'])]
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->json([
            'unread_count' => $this->notificationQueryService->countUnreadForUser(
                $this->getAuthenticatedUser(),
                $this->extractFilters($request)
            ),
        ]);
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function read(int $id): JsonResponse
    {
        $notification = $this->notificationQueryService->find($id);
        $user = $this->getAuthenticatedUser();

        if ($notification === null || $notification->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'notification_not_found'], 404);
        }

        $updated = $this->notificationQueryService->markAsRead($notification);

        return $this->json([
            'success' => true,
            'updated' => $updated,
        ]);
    }

    #[Route('/me/read-all', name: 'me_read_all', methods: ['POST'])]
    public function readAll(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $filters = $this->extractFilters($request);
        $filters['read'] = false;
        $notifications = $this->notificationQueryService->findForUser($user, $filters, 500);

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
            'category' => 'client',
        ];
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
