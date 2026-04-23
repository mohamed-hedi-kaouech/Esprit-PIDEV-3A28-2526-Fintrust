<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Form\Front\RegistrationFormType;
use App\Security\AppAuthenticator;
use App\Service\CaptchaService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class SecurityController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserService $userService,
        UserAuthenticatorInterface $userAuthenticator,
        AppAuthenticator $appAuthenticator,
    ): Response {
        if ($redirect = $this->redirectAuthenticatedUser()) {
            return $redirect;
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $userService->registerClient($user, $plainPassword);

            $this->addFlash('success', 'Compte créé avec succès. Déposez maintenant vos documents KYC pour lancer la validation.');

            return $userAuthenticator->authenticateUser($user, $appAuthenticator, $request);
        }

        return $this->render('front/security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, Request $request, CaptchaService $captchaService): Response
    {
        if ($redirect = $this->redirectAuthenticatedUser()) {
            return $redirect;
        }

        $captchaRequired = $captchaService->requiresLoginCaptcha($request->getSession());
        $captcha = $captchaRequired
            ? $captchaService->getOrCreateChallenge($request->getSession(), 'login')
            : null;

        return $this->render('front/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'captcha_required' => $captchaRequired,
            'captcha' => $captcha,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall Symfony.');
    }

    private function redirectAuthenticatedUser(): ?RedirectResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return null;
        }

        if ($user->isAdmin()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->redirectToRoute('front_dashboard');
    }
}
