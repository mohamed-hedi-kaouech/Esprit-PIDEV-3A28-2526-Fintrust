<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Form\Front\RegistrationFormType;
use App\Service\AccountVerificationMailer;
use App\Service\BiometricPasskeyService;
use App\Service\CaptchaService;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Repository\UserRepository;
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
                $this->addFlash('success', 'Compte cree avec succes. Un code de verification a ete envoye a votre adresse e-mail.');
            } catch (\Throwable) {
                $this->addFlash('warning', 'Compte cree avec succes. L envoi de l e-mail a echoue pour le moment, mais vous pouvez demander un nouveau code.');
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

    #[Route('/login/biometric/enroll/options', name: 'app_biometric_enroll_options', methods: ['POST'])]
    public function biometricEnrollOptions(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        BiometricPasskeyService $biometricPasskeyService,
    ): JsonResponse {
        $payload = $this->getJsonPayload($request);

        if (!$this->isCsrfTokenValid('biometric_login', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La demande biométrique est invalide.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json(['message' => 'Renseignez votre e-mail et votre mot de passe pour activer la biométrie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $userRepository->findByEmail($email);
        if (!$user instanceof User || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['message' => 'Identifiants invalides.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isVerified()) {
            return $this->json(['message' => 'Verifiez d abord votre adresse e-mail avant d activer la biométrie.'], Response::HTTP_CONFLICT);
        }

        if ($user->isAdmin()) {
            return $this->json(['message' => 'Utilisez l espace admin pour ce compte.'], Response::HTTP_CONFLICT);
        }

        return $this->json($biometricPasskeyService->createEnrollmentOptions(
            $user,
            $this->getBiometricRpId($request),
            $request->getSession()
        ));
    }

    #[Route('/login/biometric/enroll/verify', name: 'app_biometric_enroll_verify', methods: ['POST'])]
    public function biometricEnrollVerify(
        Request $request,
        UserRepository $userRepository,
        BiometricPasskeyService $biometricPasskeyService,
    ): JsonResponse {
        $payload = $this->getJsonPayload($request);

        if (!$this->isCsrfTokenValid('biometric_login', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La demande biométrique est invalide.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $user = $userRepository->findByEmail($email);

        if (!$user instanceof User) {
            return $this->json(['message' => 'Compte introuvable pour l activation biométrique.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $biometricPasskeyService->completeEnrollment(
                $user,
                $payload,
                $this->getBiometricOrigin($request),
                $request->getSession()
            );
        } catch (\RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'message' => 'Connexion biométrique activee avec succes sur cet appareil.',
        ]);
    }

    #[Route('/login/biometric/options', name: 'app_biometric_auth_options', methods: ['POST'])]
    public function biometricAuthOptions(
        Request $request,
        UserRepository $userRepository,
        BiometricPasskeyService $biometricPasskeyService,
    ): JsonResponse {
        $payload = $this->getJsonPayload($request);

        if (!$this->isCsrfTokenValid('biometric_login', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La demande biométrique est invalide.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return $this->json(['message' => 'Renseignez votre e-mail pour utiliser la biométrie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $userRepository->findByEmail($email);
        if (!$user instanceof User) {
            return $this->json(['message' => 'Aucun compte ne correspond a cet e-mail.'], Response::HTTP_NOT_FOUND);
        }

        if (!$user->isVerified()) {
            return $this->json(['message' => 'Votre adresse e-mail doit etre verifiee avant la connexion biométrique.'], Response::HTTP_CONFLICT);
        }

        if ($user->getStatus() === User::STATUS_SUSPENDU) {
            return $this->json(['message' => 'Votre compte est suspendu.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->json($biometricPasskeyService->createAuthenticationOptions(
                $user,
                $this->getBiometricRpId($request),
                $request->getSession()
            ));
        } catch (\RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/login/biometric/verify', name: 'app_biometric_auth_verify', methods: ['POST'])]
    public function biometricAuthVerify(
        Request $request,
        UserRepository $userRepository,
        BiometricPasskeyService $biometricPasskeyService,
    ): JsonResponse {
        $payload = $this->getJsonPayload($request);

        if (!$this->isCsrfTokenValid('biometric_login', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['message' => 'La demande biométrique est invalide.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $user = $userRepository->findByEmail($email);

        if (!$user instanceof User) {
            return $this->json(['message' => 'Compte biométrique introuvable.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $biometricPasskeyService->verifyAuthentication(
                $user,
                $payload,
                $this->getBiometricOrigin($request),
                $request->getSession()
            );
        } catch (\RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNAUTHORIZED);
        }

        $this->loginUser($user, 'main');

        return $this->json([
            'message' => 'Identite biométrique confirmee. Connexion en cours...',
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

    private function getBiometricOrigin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    private function getBiometricRpId(Request $request): string
    {
        return (string) $request->getHost();
    }
}
