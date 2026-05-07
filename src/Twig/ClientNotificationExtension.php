<?php

namespace App\Twig;

use App\Entity\User\User;
use App\Service\DynamicClientNotificationService;
use App\Service\NotificationService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ClientNotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly DynamicClientNotificationService $dynamicNotificationService,
        private readonly NotificationService $notificationService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('front_notification_badge_count', [$this, 'getFrontNotificationBadgeCount']),
        ];
    }

    public function getFrontNotificationBadgeCount(?User $user): int
    {
        if (!$user instanceof User) {
            return 0;
        }

        $session = $this->requestStack->getSession();

        return $this->notificationService->getUnreadCountForUser($user)
            + $this->dynamicNotificationService->getUnreadCount($user, $session);
    }
}
