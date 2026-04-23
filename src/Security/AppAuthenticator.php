<?php

namespace App\Security;

use App\Entity\User\User;
use App\Repository\UserRepository;
use App\Service\CaptchaService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserRepository $userRepository,
        private readonly CaptchaService $captchaService,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');
        $adminLogin = (bool) $request->request->get('_admin_login', false);
        $session = $request->getSession();

        $session->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        if ($this->captchaService->requiresLoginCaptcha($session)) {
            $token = (string) $request->request->get('captcha_token', '');
            $confirmed = $request->request->getBoolean('captcha_confirm');
            if (!$this->captchaService->validateAnswer($session, 'login', $token, $confirmed)) {
                $this->captchaService->refreshChallenge($session, 'login');
                throw new CustomUserMessageAuthenticationException('Le CAPTCHA de connexion est invalide.');
            }
        }

        return new Passport(
            new UserBadge(
                $email,
                function (string $userIdentifier) use ($adminLogin): User {
                    $user = $this->userRepository->findByEmail($userIdentifier);

                    if (!$user instanceof User) {
                        throw new CustomUserMessageAuthenticationException('Identifiants invalides.');
                    }

                    if ($adminLogin && !$user->isAdmin()) {
                        throw new CustomUserMessageAuthenticationException(
                            'Cette page est réservée aux administrateurs.'
                        );
                    }

                    return $user;
                }
            ),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();
        $this->captchaService->resetLoginFailures($request->getSession());
        $this->captchaService->clearChallenge($request->getSession(), 'login');

        if ($user->getStatus() === User::STATUS_SUSPENDU) {
            throw new CustomUserMessageAuthenticationException(
                'Votre compte a été suspendu. Veuillez contacter le support FinTrust.'
            );
        }

        if ($user->isAdmin()) {
            if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
                return new RedirectResponse($targetPath);
            }

            return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('front_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $session = $request->getSession();
        $this->captchaService->incrementLoginFailures($session);

        if ($this->captchaService->requiresLoginCaptcha($session)) {
            $this->captchaService->refreshChallenge($session, 'login');
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        if (str_starts_with($request->getPathInfo(), '/admin')) {
            return $this->urlGenerator->generate('admin_login');
        }

        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
