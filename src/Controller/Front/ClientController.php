<?php

namespace App\Controller\Front;

use App\Entity\User\Client\Kyc;
use App\Entity\User\User;
use App\Form\Front\KycFormType;
use App\Form\Front\ProfileFormType;
use App\Repository\KycRepository;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\BehavioralProfileService;
use App\Service\AdvancedAnalyticsService;
use App\Service\AccountDeactivationRequestService;
use App\Service\CaptchaService;
use App\Service\EconomicDataService;
use App\Service\FinancialNewsService;
use App\Service\KycService;
use App\Service\MarketWatchService;
use App\Service\NotificationService;
use App\Service\KycVerificationCenterService;
use App\Service\QrCodeService;
use App\Service\UserIntelligenceService;
use App\Service\UserService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client', name: 'front_')]
class ClientController extends AbstractController
{
    private const FRONT_MODULES = [
        'wallet' => [
            'title' => 'Wallet',
            'icon' => 'bi-wallet2',
            'description' => 'Consultez vos soldes, vos transactions et vos moyens de paiement.',
        ],
        'product' => [
            'title' => 'Product',
            'icon' => 'bi-box-seam',
            'description' => 'Decouvrez les produits et services FinTrust adaptes a votre profil.',
        ],
        'loan' => [
            'title' => 'Loan',
            'icon' => 'bi-bank',
            'description' => 'Deposez et suivez vos demandes de pret depuis votre espace client.',
        ],
        'budget' => [
            'title' => 'Budget',
            'icon' => 'bi-calculator',
            'description' => 'Pilotez votre budget et suivez vos objectifs financiers.',
        ],
        'publications' => [
            'title' => 'Publications',
            'icon' => 'bi-newspaper',
            'description' => 'Parcourez les publications et actualites disponibles sur la plateforme.',
        ],
    ];

    public function __construct(
        private readonly KycRepository $kycRepository,
        private readonly KycService $kycService,
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
        private readonly QrCodeService $qrCodeService,
        private readonly UserIntelligenceService $userIntelligenceService,
        private readonly FinancialNewsService $financialNewsService,
        private readonly EconomicDataService $economicDataService,
        private readonly MarketWatchService $marketWatchService,
        private readonly AdvancedAnalyticsService $advancedAnalyticsService,
        private readonly AccountDeactivationRequestService $accountDeactivationRequestService,
        private readonly KycVerificationCenterService $kycVerificationCenterService,
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly ValidatorInterface $validator,
        private readonly BehavioralProfileService $behavioralProfileService,
    ) {}

    #[Route('/tableau-de-bord', name: 'dashboard')]
    public function dashboard(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        $kyc = $this->kycRepository->findLatestByUser($user);

        return $this->render('front/client/dashboard.html.twig', [
            'user' => $user,
            'kyc' => $kyc,
            'newsFeed' => $this->financialNewsService->getUserFeed($user, 3),
            'analyticsNews' => $this->advancedAnalyticsService->getNewsRelevance($user, 3),
            'recommendedAction' => $this->advancedAnalyticsService->getNextBestAction($user),
            'dropoffRisk' => $this->advancedAnalyticsService->getDropoffRisk($user),
            'economyOverview' => $this->economicDataService->getOverview(),
            'watchlistHighlights' => $this->marketWatchService->getWatchlistHighlights(3),
        ]);
    }

    #[Route('/actualites-pertinentes', name: 'relevant_news', methods: ['GET'])]
    public function relevantNews(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        return $this->render('front/client/relevant_news.html.twig', [
            'user' => $user,
            'relevance' => $this->advancedAnalyticsService->getNewsRelevance($user, 6),
        ]);
    }

    #[Route('/actions-recommandees', name: 'recommended_actions', methods: ['GET'])]
    public function recommendedActions(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        return $this->render('front/client/recommended_actions.html.twig', [
            'user' => $user,
            'recommendation' => $this->advancedAnalyticsService->getNextBestAction($user),
            'prioritySummary' => $this->advancedAnalyticsService->getActionPrioritySummary($user),
            'dropoffRisk' => $this->advancedAnalyticsService->getDropoffRisk($user),
        ]);
    }

    #[Route('/coherence-identitaire', name: 'identity_consistency', methods: ['GET'])]
    public function identityConsistency(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        return $this->render('front/client/identity_consistency.html.twig', [
            'user' => $user,
            'identityConsistency' => $this->advancedAnalyticsService->getIdentityConsistency($user),
        ]);
    }

    #[Route('/marches-economie', name: 'markets', methods: ['GET'])]
    public function markets(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        $selectedSymbol = (string) $request->query->get('symbol', '');
        $watchlist = $this->marketWatchService->getWatchlist();
        $defaultSymbol = $selectedSymbol !== '' ? $selectedSymbol : ($watchlist[0]['symbol'] ?? 'AAPL');
        $assetDetail = $this->marketWatchService->getAssetDetail($defaultSymbol);

        return $this->render('front/client/markets.html.twig', [
            'user' => $user,
            'economyOverview' => $this->economicDataService->getOverview(),
            'inflation' => $this->economicDataService->getInflation(),
            'rates' => $this->economicDataService->getRates(),
            'currencies' => $this->economicDataService->getCurrencies(),
            'indicators' => $this->economicDataService->getIndicators(),
            'watchlist' => $watchlist,
            'watchlistHighlights' => $this->marketWatchService->getHighlights(),
            'trendingAssets' => $this->marketWatchService->getTrending(),
            'discoverableAssets' => $this->marketWatchService->getDiscoverableAssets(),
            'marketOverview' => $this->marketWatchService->getMarketOverview(),
            'assetDetail' => $assetDetail,
        ]);
    }

    #[Route('/marches-economie/watchlist/add', name: 'watchlist_add', methods: ['POST'])]
    public function addToWatchlist(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('watchlist_add', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande est invalide.');

            return $this->redirectToRoute('front_markets');
        }

        $symbol = (string) $request->request->get('symbol', '');
        if ($symbol === '' || !$this->marketWatchService->addToWatchlist($symbol)) {
            $this->addFlash('error', 'Actif introuvable.');

            return $this->redirectToRoute('front_markets');
        }

        $this->addFlash('success', 'Actif ajoute a votre watchlist.');

        return $this->redirectToRoute('front_markets', ['symbol' => $symbol]);
    }

    #[Route('/marches-economie/watchlist/remove', name: 'watchlist_remove', methods: ['POST'])]
    public function removeFromWatchlist(Request $request): RedirectResponse
    {
        $symbol = (string) $request->request->get('symbol', '');

        if ($symbol === '') {
            $this->addFlash('error', 'Le symbole a retirer est invalide.');

            return $this->redirectToRoute('front_markets');
        }

        if (!$this->isCsrfTokenValid('watchlist_remove_' . $symbol, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande est invalide.');

            return $this->redirectToRoute('front_markets');
        }

        $this->marketWatchService->removeFromWatchlist($symbol);
        $this->addFlash('success', 'Actif retire de votre watchlist.');

        return $this->redirectToRoute('front_markets');
    }

    #[Route('/actualites-financieres', name: 'news', methods: ['GET'])]
    public function news(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        $category = strtoupper((string) $request->query->get('category', ''));
        $country = strtolower((string) $request->query->get('country', ''));
        $keyword = trim((string) $request->query->get('keyword', ''));

        $articles = $this->financialNewsService->getFinancialNews([
            'category' => $category !== '' ? $category : null,
            'country' => $country !== '' ? $country : null,
            'keyword' => $keyword !== '' ? $keyword : null,
            'limit' => 12,
        ]);

        return $this->render('front/client/news.html.twig', [
            'user' => $user,
            'articles' => $articles,
            'headline' => $articles[0] ?? null,
            'marketHighlights' => $this->financialNewsService->getMarketNews(3),
            'bankHighlights' => $this->financialNewsService->getBankNews(3),
            'categoryCounts' => $this->financialNewsService->getCategoryCounts(),
            'selectedCategory' => $category,
            'selectedCountry' => $country,
            'selectedKeyword' => $keyword,
        ]);
    }

    #[Route('/profil', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->updateProfile($user);
            $this->addFlash('success', 'Votre profil a ete mis a jour avec succes.');

            return $this->redirectToRoute('front_profile');
        }

        $baseUrl = $request->getSchemeAndHttpHost();
        $publicProfileUrl = $user->getQrToken()
            ? $this->qrCodeService->getPublicProfileUrl($user->getQrToken(), $baseUrl)
            : null;
        $localProfileUrl = $user->getQrToken()
            ? $this->generateUrl('front_qr_view', ['token' => $user->getQrToken()])
            : null;
        $qrUrl = $user->getQrToken()
            ? $this->generateUrl('front_qr_code_image', ['token' => $user->getQrToken()])
            : null;
        $qrNeedsPublicUrl = $user->getQrToken()
            ? $this->qrCodeService->isLocalOnlyUrl($baseUrl)
            : false;

        return $this->render('front/client/profile.html.twig', [
            'form' => $form,
            'user' => $user,
            'qrUrl' => $qrUrl,
            'publicProfileUrl' => $publicProfileUrl,
            'localProfileUrl' => $localProfileUrl,
            'qrNeedsPublicUrl' => $qrNeedsPublicUrl,
            'intelligenceProfile' => $this->userIntelligenceService->buildProfileEnrichment($user),
            'riskProfile' => $this->userIntelligenceService->getRiskProfile($user),
            'financialBehavior' => $this->userIntelligenceService->getFinancialBehaviorSummary($user),
            'monitoringTimeline' => $this->userIntelligenceService->buildMonitoringTimeline($user),
            'dropoffRisk' => $this->advancedAnalyticsService->getDropoffRisk($user),
            'deactivationRequest' => $this->accountDeactivationRequestService->getLatestForUser($user),
        ]);
    }

    #[Route('/profil/desactivation', name: 'profile_deactivation_request', methods: ['POST'])]
    public function requestDeactivation(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('profile_deactivation_request', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande de desactivation est invalide.');

            return $this->redirectToRoute('front_profile');
        }

        $reason = trim((string) $request->request->get('reason', ''));
        $impact = trim((string) $request->request->get('impact_summary', ''));

        if (mb_strlen($reason) < 20) {
            $this->addFlash('error', 'Precisez une justification plus detaillee pour votre demande de desactivation.');

            return $this->redirectToRoute('front_profile');
        }

        try {
            $requestData = $this->accountDeactivationRequestService->submit(
                $user,
                $reason,
                $impact,
                $this->advancedAnalyticsService->getDropoffRisk($user)
            );

            $this->notificationService->notify(
                $user,
                'Votre demande de desactivation de compte a bien ete enregistree. Notre equipe admin va l examiner avant toute fermeture.',
                'WARNING'
            );

            $this->addFlash('success', 'Votre demande de desactivation a ete transmise pour revue admin.');
        } catch (\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('front_profile');
    }

    #[Route('/preferences/theme/{mode}', name: 'theme_switch', methods: ['POST'])]
    public function switchTheme(string $mode, Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('switch_theme', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande de changement de theme est invalide.');

            return $this->redirectToRoute('front_dashboard');
        }

        if (!in_array($mode, [User::THEME_LIGHT, User::THEME_DARK], true)) {
            $this->addFlash('error', 'Le theme demande est invalide.');

            return $this->redirectToRoute('front_dashboard');
        }

        $user->setThemeMode($mode);
        $this->userService->updateProfile($user);

        $this->addFlash('success', sprintf('Theme %s active.', $mode === User::THEME_DARK ? 'sombre' : 'clair'));

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('front_dashboard'));
    }

    #[Route('/kyc/depot', name: 'kyc_submit', methods: ['GET', 'POST'])]
    public function kycSubmit(Request $request, CaptchaService $captchaService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isKycApproved()) {
            $this->addFlash('info', 'Votre KYC est deja approuve.');

            return $this->redirectToRoute('front_dashboard');
        }

        if ($user->getKycStatus() === User::KYC_EN_ATTENTE) {
            $this->addFlash('info', 'Votre dossier est en cours d\'examen par notre equipe.');

            return $this->redirectToRoute('front_kyc_status');
        }

        $kyc = new Kyc();
        $form = $this->createForm(KycFormType::class, $kyc);
        $form->handleRequest($request);
        $captcha = $captchaService->getOrCreateChallenge($request->getSession(), 'kyc_submit');

        if ($form->isSubmitted()) {
            $payload = $request->request->all()[$form->getName()] ?? [];
            $filesBag = $request->files->all()[$form->getName()] ?? [];

            $kyc->setCin((string) ($payload['cin'] ?? ''));
            $kyc->setAdresse((string) ($payload['adresse'] ?? ''));

            if (!empty($payload['dateNaissance'])) {
                try {
                    $kyc->setDateNaissance(new \DateTime((string) $payload['dateNaissance']));
                } catch (\Exception) {
                    // La validation Symfony remontera une erreur lisible si la date n'est pas correcte.
                }
            }

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[]|\Symfony\Component\HttpFoundation\File\UploadedFile|null $rawFiles */
            $rawFiles = $filesBag['documents'] ?? $form->get('documents')->getData();
            $files = is_array($rawFiles) ? array_values(array_filter($rawFiles)) : ($rawFiles ? [$rawFiles] : []);
            $signatureData = (string) ($payload['signatureData'] ?? $form->get('signatureData')->getData() ?? '');
            $selfieData = (string) ($payload['selfieData'] ?? $form->get('selfieData')->getData() ?? '');
            $selfieFingerprintData = (string) ($payload['selfieFingerprintData'] ?? $form->get('selfieFingerprintData')->getData() ?? '');

            $entityErrors = $this->validator->validate($kyc);

            if (count($entityErrors) > 0) {
                $shown = [];
                foreach ($entityErrors as $error) {
                    $message = $error->getMessage();
                    if (!in_array($message, $shown, true)) {
                        $this->addFlash('error', $message);
                        $shown[] = $message;
                    }
                }
            } elseif ($files === []) {
                $this->addFlash('error', 'Veuillez joindre au moins un document justificatif.');
            } elseif ($selfieData === '') {
                $this->addFlash('error', 'Ajoutez un selfie KYC de reference pour activer la connexion selfie sur votre compte.');
            } elseif ($selfieFingerprintData === '') {
                $this->addFlash('error', 'L empreinte du selfie KYC est absente. Reprenez votre selfie de reference.');
            } elseif (!$this->hasValidKycFiles($files)) {
                $this->addFlash('error', 'Chaque justificatif doit etre en JPG, PNG ou PDF, avec une taille maximale de 5 Mo.');
            } elseif (!$captchaService->validateAnswer(
                $request->getSession(),
                'kyc_submit',
                (string) $request->request->get('captcha_token', ''),
                $request->request->getBoolean('captcha_confirm'),
                (string) $request->request->get('recaptcha_token', ''),
                $request->getClientIp()
            )) {
                $captchaService->refreshChallenge($request->getSession(), 'kyc_submit');
                $captcha = $captchaService->getOrCreateChallenge($request->getSession(), 'kyc_submit');
                $this->addFlash('error', 'Le CAPTCHA KYC est invalide. Veuillez recommencer.');
            } else {
                try {
                    $this->kycService->submitKyc($user, $kyc, $files, $signatureData, $selfieData, $selfieFingerprintData);
                    $this->notificationService->notifyKycSubmitted($user);
                    $captchaService->clearChallenge($request->getSession(), 'kyc_submit');

                    if ($signatureData === '') {
                        $this->addFlash('warning', 'Le dossier KYC a ete soumis sans signature capturee. Vous pourrez la redeposer si necessaire.');
                    } else {
                        $this->addFlash('success', 'Dossier KYC soumis avec succes. Vous serez notifie apres examen.');
                    }

                    return $this->redirectToRoute('front_kyc_status');
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('error', 'Un dossier KYC existe deja avec ce CIN. Veuillez verifier votre saisie.');
                }
            }
        }

        return $this->render('front/client/kyc_submit.html.twig', [
            'form' => $form,
            'user' => $user,
            'captcha' => $captcha,
        ]);
    }

    /**
     * @param \Symfony\Component\HttpFoundation\File\UploadedFile[] $files
     */
    private function hasValidKycFiles(array $files): bool
    {
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        $maxSize = 5 * 1024 * 1024;

        foreach ($files as $file) {
            if (!$file) {
                return false;
            }

            if (!in_array($file->getMimeType(), $allowedMimeTypes, true)) {
                return false;
            }

            if (($file->getSize() ?? 0) > $maxSize) {
                return false;
            }
        }

        return true;
    }

    #[Route('/kyc/statut', name: 'kyc_status')]
    public function kycStatus(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $kyc = $this->kycRepository->findLatestByUser($user);

        return $this->render('front/client/kyc_status.html.twig', [
            'user' => $user,
            'kyc' => $kyc,
            'kycCenter' => $this->kycVerificationCenterService->buildCenter($user),
        ]);
    }

    #[Route('/module/{slug}', name: 'module', methods: ['GET'])]
    public function module(string $slug): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->behavioralProfileService->refreshUserBehavior($user);

        if (!isset(self::FRONT_MODULES[$slug])) {
            throw $this->createNotFoundException('Module introuvable.');
        }

        if ($redirect = $this->kycAccessChecker->check($user)) {
            $this->addFlash('warning', 'Votre KYC doit etre approuve pour acceder a ce module.');

            return $redirect;
        }

        if (in_array($slug, ['wallet', 'loan', 'product', 'budget'], true) && ($redirect = $this->riskAccessChecker->checkSensitiveModule($user))) {
            $this->addFlash('warning', 'Votre niveau de risque est critique. Les actions sensibles sont temporairement restreintes.');

            return $redirect;
        }

        return $this->render('front/client/module.html.twig', [
            'module' => self::FRONT_MODULES[$slug],
        ]);
    }

    #[Route('/notifications', name: 'notifications')]
    public function notifications(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $notifs = $this->userService->getNotifications($user);
        $unreadCount = count(array_filter($notifs, static fn ($notif) => !$notif->isRead()));

        return $this->render('front/client/notifications.html.twig', [
            'notifications' => $notifs,
            'unreadCount' => $unreadCount,
        ]);
    }

    #[Route('/notifications/{id}/read', name: 'notifications_read', methods: ['POST'])]
    public function markNotificationAsRead(int $id, Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('read_notification_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande est invalide.');

            return $this->redirectToRoute('front_notifications');
        }

        if (!$this->notificationService->markAsReadForUser($id, $user)) {
            $this->addFlash('error', 'Notification introuvable.');

            return $this->redirectToRoute('front_notifications');
        }

        $this->addFlash('success', 'Notification marquee comme lue.');

        return $this->redirectToRoute('front_notifications');
    }

    #[Route('/notifications/read-all', name: 'notifications_read_all', methods: ['POST'])]
    public function markAllNotificationsAsRead(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('read_all_notifications', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La demande est invalide.');

            return $this->redirectToRoute('front_notifications');
        }

        $updated = $this->notificationService->markAllAsReadForUser($user);

        if ($updated > 0) {
            $this->addFlash('success', $updated > 1 ? 'Toutes les notifications ont ete marquees comme lues.' : 'La notification a ete marquee comme lue.');
        } else {
            $this->addFlash('info', 'Aucune notification non lue a mettre a jour.');
        }

        return $this->redirectToRoute('front_notifications');
    }

    #[Route('/qr/{token}', name: 'qr_view', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function qrView(string $token): Response
    {
        $user = $this->userService->findByQrToken($token);

        if (!$user) {
            throw $this->createNotFoundException('QR code invalide ou expire.');
        }

        return $this->render('front/client/qr_view.html.twig', ['user' => $user]);
    }
}
