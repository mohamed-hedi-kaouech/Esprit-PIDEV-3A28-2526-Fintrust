<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Form\Front\RegistrationFormType;
use App\Service\AccountVerificationMailer;
use App\Service\CaptchaService;
use App\Service\UserService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserService $userService,
        AccountVerificationMailer $accountVerificationMailer,
        LoggerInterface $logger,
    ): Response {
        if ($redirect = $this->redirectAuthenticatedUser()) {
            return $redirect;
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $userService->registerClient($user, $plainPassword);

            try {
                $accountVerificationMailer->sendVerificationCode($user);
                $this->addFlash('success', 'Compte cree avec succes. Un code de verification a ete envoye a votre adresse e-mail.');
                $logger->info('Email de verification envoye avec succes', [
                    'user_email' => $user->getEmail(),
                    'verification_code' => $user->getEmailVerificationCode(),
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Compte cree avec succes. L envoi de l e-mail a echoue pour le moment, mais vous pouvez demander un nouveau code.');
                $this->addVerificationCodeFallbackFlash($user);
                $logger->error('Erreur lors de l envoi du code de verification', [
                    'user_email' => $user->getEmail(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            return $this->redirectToRoute('app_verify_account', [
                'email' => $user->getEmail(),
            ]);
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
        throw new \LogicException('Intercepte par le firewall Symfony.');
    }

    private function addVerificationCodeFallbackFlash(User $user): void
    {
        if ($this->getParameter('kernel.environment') !== 'dev') {
            return;
        }

        $code = $user->getEmailVerificationCode();
        if (!is_string($code) || $code === '') {
            return;
        }

        $this->addFlash(
            'info',
            sprintf('Mode dev: e-mail indisponible sur cette machine. Utilisez ce code de verification: %s', $code)
        );
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
