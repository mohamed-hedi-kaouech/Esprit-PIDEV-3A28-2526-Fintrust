<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class LogoutRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $session = $request->getSession();

        if (null !== $session) {
            $session->clear();
            $session->invalidate();
        }

        $from = (string) $event->getRequest()->query->get('from', '');
        $route = $from === 'admin' ? 'admin_login' : 'app_login';
        $response = new RedirectResponse($this->urlGenerator->generate($route));
        $response->headers->set('Clear-Site-Data', '"cache", "cookies", "storage"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->clearCookie('PHPSESSID');
        $response->headers->setCookie(Cookie::create('PHPSESSID')->withValue('')->withExpires(1)->withPath('/'));

        $event->setResponse($response);
    }
}
