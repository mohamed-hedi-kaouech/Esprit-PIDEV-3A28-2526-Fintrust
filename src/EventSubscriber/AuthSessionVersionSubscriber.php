<?php

namespace App\EventSubscriber;

use App\Entity\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class AuthSessionVersionSubscriber implements EventSubscriberInterface
{
    public const SESSION_KEY = 'fintrust.auth_session_version';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $session = $request->getSession();
        $storedVersion = $session->get(self::SESSION_KEY);
        if ($storedVersion === null) {
            $session->set(self::SESSION_KEY, $user->getAuthSessionVersion());

            return;
        }

        if ((int) $storedVersion === $user->getAuthSessionVersion()) {
            return;
        }

        $session->invalidate();
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login', [
            'reset' => 1,
        ])));
    }
}
