<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Form\Front\VerifyAccountCodeType;
use App\Repository\UserRepository;
use App\Service\AccountVerificationMailer;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VerifyAccountController extends AbstractController
{
    #[Route('/verification-compte', name: 'app_verify_account', methods: ['GET', 'POST'])]
    public function verify(
        Request $request,
        UserRepository $userRepository,
        UserService $userService,
    ): Response {
        $prefilledEmail = (string) $request->query->get('email', '');
        $form = $this->createForm(VerifyAccountCodeType::class, null, [
            'prefilled_email' => $prefilledEmail,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = trim((string) $form->get('email')->getData());
            $code = trim((string) $form->get('code')->getData());
            $user = $userRepository->findByEmail($email);

            if ($user === null) {
                $this->addFlash('error', 'Aucun compte FinTrust ne correspond a cette adresse e-mail.');
            } elseif ($user->isVerified()) {
                $this->addFlash('info', 'Votre compte est deja verifie. Vous pouvez vous connecter.');

                return $this->redirectToRoute('app_login');
            } elseif ($userService->isVerificationCodeExpired($user)) {
                $this->addFlash('error', 'Le code de verification a expire. Demandez un nouveau code.');
            } elseif (!$userService->isVerificationCodeValid($user, $code)) {
                $this->addFlash('error', 'Le code de verification est invalide. Verifiez votre saisie.');
            } else {
                $userService->markEmailAsVerified($user);
                $this->addFlash('success', 'Votre adresse e-mail a bien ete verifiee. Vous pouvez maintenant vous connecter a FinTrust.');

                return $this->redirectToRoute('app_login');
            }

            return $this->redirectToRoute('app_verify_account', ['email' => $email]);
        }

        return $this->render('front/security/verify_account.html.twig', [
            'verifyForm' => $form,
            'prefilledEmail' => $prefilledEmail,
        ]);
    }

    #[Route('/verification-compte/renvoyer', name: 'app_verify_account_resend', methods: ['POST'])]
    public function resend(
        Request $request,
        UserRepository $userRepository,
        UserService $userService,
        AccountVerificationMailer $accountVerificationMailer,
    ): Response {
        $email = trim((string) $request->request->get('email', ''));

        if (!$this->isCsrfTokenValid('resend_verification_code', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande de renvoi est invalide. Veuillez reessayer.');

            return $this->redirectToRoute('app_verify_account', ['email' => $email]);
        }

        $user = $userRepository->findByEmail($email);

        if ($user === null) {
            $this->addFlash('error', 'Aucun compte FinTrust ne correspond a cette adresse e-mail.');

            return $this->redirectToRoute('app_verify_account');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Votre compte est deja verifie. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        $userService->refreshEmailVerificationCode($user);

        try {
            $accountVerificationMailer->sendVerificationCode($user);
            $this->addFlash('success', 'Un nouveau code de verification a ete envoye a votre adresse e-mail.');
        } catch (\Throwable) {
            $this->addFlash('warning', 'Le code a bien ete regenere, mais l envoi e-mail a echoue.');
            $this->addVerificationCodeFallbackFlash($user);
        }

        return $this->redirectToRoute('app_verify_account', ['email' => $email]);
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
            sprintf('Mode dev: e-mail indisponible sur cette machine. Nouveau code de verification: %s', $code)
        );
    }
}
