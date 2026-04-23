<?php

namespace App\Twig;

use App\Entity\User\User;
use App\Service\NotificationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NotificationExtension extends AbstractExtension
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', [$this, 'countUnread']),
        ];
    }

    public function countUnread(?User $user): int
    {
        if (!$user instanceof User) {
            return 0;
        }

        return $this->notificationService->countUnreadForUser($user);
    }
}
