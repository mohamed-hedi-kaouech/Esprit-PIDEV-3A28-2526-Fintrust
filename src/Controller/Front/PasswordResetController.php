<?php

namespace App\Controller\Front;

use App\Entity\User\Client\PasswordResetRequest;
use App\Form\Front\ForgotPasswordRequestType;
use App\Form\Front\ResetPasswordType;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mot-de-passe-oublie')]
class PasswordResetController extends AbstractController
{
    #[Route('', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, PasswordResetService $passwordResetService): Response
    {
        $form = $this->createForm(ForgotPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $identifier = (string) $form->get('identifier')->getData();
            $flow = $passwordResetService->initiateResetFlow($identifier, $request);
            $dispatch = $passwordResetService->dispatchEmail($flow, $request);

            if (($dispatch['sent'] ?? false) === true) {
                $this->addFlash('info', 'Si un compte correspond aux informations saisies, un e-mail de reinitialisation a ete envoye.');
            } else {
                $this->addFlash('warning', 'L envoi de l e-mail de reinitialisation a echoue sur cette machine.');

                $debugResetUrl = $dispatch['debug_reset_url'] ?? null;
                if (is_string($debugResetUrl) && $debugResetUrl !== '' && $this->getParameter('kernel.environment') === 'dev') {
                    $this->addFlash('info', sprintf('Mode dev: utilisez directement ce lien de reinitialisation: %s', $debugResetUrl));
                }
            }

            return $this->redirectToRoute('app_login');
        }

        return $this->render('front/security/forgot_password.html.twig', [
            'requestForm' => $form,
        ]);
    }

    #[Route('/email/{publicId}/{token}', name: 'app_password_reset_email_verify', methods: ['GET'])]
    public function verifyEmail(string $publicId, string $token, Request $request, PasswordResetService $passwordResetService): Response
    {
        $passwordResetRequest = $passwordResetService->verifyEmailToken($publicId, $token, $request);

        if (!$passwordResetRequest instanceof PasswordResetRequest) {
            $this->addFlash('danger', 'Le lien de reinitialisation est invalide ou expire.');

            return $this->redirectToRoute('app_forgot_password');
        }

        $request->getSession()->set(PasswordResetService::SESSION_AUTH_KEY, [
            'publicId' => $passwordResetRequest->getPublicId(),
        ]);

        $this->addFlash('success', 'Lien valide. Vous pouvez maintenant choisir un nouveau mot de passe.');

        return $this->redirectToRoute('app_forgot_password_new');
    }

    #[Route('/nouveau', name: 'app_forgot_password_new', methods: ['GET', 'POST'])]
    public function newPassword(Request $request, PasswordResetService $passwordResetService): Response
    {
        $auth = $request->getSession()->get(PasswordResetService::SESSION_AUTH_KEY);
        $publicId = is_array($auth) ? ($auth['publicId'] ?? null) : null;
        if (!is_string($publicId) || $publicId === '') {
            return $this->redirectToRoute('app_forgot_password');
        }

        $passwordResetRequest = $passwordResetService->getAuthorizedResetRequest($publicId);
        if (!$passwordResetRequest instanceof PasswordResetRequest) {
            $request->getSession()->remove(PasswordResetService::SESSION_AUTH_KEY);
            $this->addFlash('danger', 'La demande de reinitialisation n est plus valide.');

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $passwordResetService->resetPassword($passwordResetRequest, $plainPassword, $request);
            $request->getSession()->remove(PasswordResetService::SESSION_AUTH_KEY);

            $this->addFlash('success', 'Votre mot de passe a ete reinitialise avec succes. Veuillez vous reconnecter.');

            return $this->redirectToRoute('app_login', ['reset' => 1]);
        }

        return $this->render('front/security/reset_password.html.twig', [
            'resetPasswordForm' => $form,
            'resetRequest' => $passwordResetRequest,
        ]);
    }
}
