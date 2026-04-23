<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Form\Front\RegistrationFormType;
use App\Repository\UserRepository;
use App\Service\AccountVerificationMailer;
use App\Service\CaptchaService;
use App\Service\SelfieKycAuthService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserService $userService,
        AccountVerificationMailer $accountVerificationMailer,
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
                $this->addFlash('success', 'Compte cree avec succes. Un code de verification a ete envoye par e-mail. Verifiez aussi les dossiers Spam et Promotions de Gmail.');
            } catch (\Throwable $exception) {
                $this->addFlash('error', 'Compte cree avec succes, mais l e-mail de verification n a pas pu etre envoye. Verifiez la configuration SMTP FinTrust puis renvoyez un nouveau code.');
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

    #[Route('/login/selfie/enroll', name: 'app_selfie_enroll', methods: ['POST'])]
    public function selfieEnroll(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        SelfieKycAuthService $selfieKycAuthService,
    ): JsonResponse {
        $payload = $this->getJsonPayload($request);

        if (!$this->isCsrfTokenValid('selfie_auth', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['code' => 'invalid_request', 'message' => 'La demande selfie est invalide.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $selfie = (string) ($payload['selfie'] ?? '');
        $fingerprint = (string) ($payload['fingerprint'] ?? '');

        if ($email === '' || $password === '' || $selfie === '' || $fingerprint === '') {
            return $this->json(['code' => 'missing_credentials', 'message' => 'Renseignez votre e-mail, votre mot de passe et capturez votre selfie pour activer ce mode de connexion.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $userRepository->findByEmail($email);
        if (!$user instanceof User || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['code' => 'invalid_credentials', 'message' => 'E-mail ou mot de passe invalide.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isVerified()) {
            return $this->json(['code' => 'email_not_verified', 'message' => 'Verifiez d abord votre adresse e-mail avant d activer la connexion selfie KYC.'], Response::HTTP_CONFLICT);
        }

        if ($user->isAdmin()) {
            return $this->json(['code' => 'admin_account', 'message' => 'Cette connexion selfie est reservee a l espace client.'], Response::HTTP_CONFLICT);
        }

        try {
            $result = $selfieKycAuthService->storeReferenceSelfie($user, $selfie, $fingerprint);
        } catch (\RuntimeException $exception) {
            return $this->json(['code' => 'enrollment_failed', 'message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result);
    }

    #[Route('/login/selfie/auth', name: 'app_selfie_auth', methods: ['POST'])]
    public function selfieAuth(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        SelfieKycAuthService $selfieKycAuthService,
        Security $security,
    ): JsonResponse {
        $payload = $this->getJsonPayload($request);

        if (!$this->isCsrfTokenValid('selfie_auth', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['code' => 'invalid_request', 'message' => 'La demande selfie est invalide.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $fingerprint = (string) ($payload['fingerprint'] ?? '');

        if ($email === '' || $password === '' || $fingerprint === '') {
            return $this->json(['code' => 'missing_credentials', 'message' => 'Renseignez votre e-mail, votre mot de passe et capturez votre selfie pour continuer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $userRepository->findByEmail($email);
        if (!$user instanceof User) {
            return $this->json(['code' => 'user_not_found', 'message' => 'Aucun compte ne correspond a cet e-mail.'], Response::HTTP_NOT_FOUND);
        }

        if (!$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['code' => 'invalid_credentials', 'message' => 'E-mail ou mot de passe invalide.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isVerified()) {
            return $this->json(['code' => 'email_not_verified', 'message' => 'Votre adresse e-mail doit etre verifiee avant la connexion selfie KYC.'], Response::HTTP_CONFLICT);
        }

        if ($user->getStatus() === User::STATUS_SUSPENDU) {
            return $this->json(['code' => 'account_suspended', 'message' => 'Votre compte est suspendu.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $selfieKycAuthService->verifySelfie($user, $fingerprint);
        } catch (\RuntimeException $exception) {
            return $this->json(['code' => 'selfie_unavailable', 'message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$result['matched']) {
            return $this->json([
                'code' => 'selfie_mismatch',
                'message' => $result['message'],
                'score' => $result['score'],
                'threshold' => $result['threshold'],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $security->login($user, 'App\\Security\\AppAuthenticator', 'main');

        return $this->json([
            'message' => 'Selfie reconnu et mot de passe valide. Connexion en cours...',
            'score' => $result['score'],
            'redirectUrl' => $this->generateUrl('front_dashboard'),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepte par le firewall Symfony.');
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

    /**
     * @return array<string, mixed>
     */
    private function getJsonPayload(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return is_array($data) ? $data : [];
    }

}
