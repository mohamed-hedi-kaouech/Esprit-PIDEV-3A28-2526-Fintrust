<?php

namespace App\Controller\Front;

use App\Entity\User\Client\Kyc;
use App\Entity\Categorie\Alerte;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use App\Entity\User\User;
use App\Form\Front\KycFormType;
use App\Form\Front\ProfileFormType;
use App\Entity\Publication\Publication;
use App\Form\Front\PublicationCommentType;
use App\Repository\KycRepository;
use App\Repository\PublicationRepository;
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
use App\Service\RewardService;
use App\Service\UserIntelligenceService;
use App\Service\UserService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly PublicationRepository $publicationRepository,
        private readonly CategorieRepository $categorieRepository,
        private readonly ItemRepository $itemRepository,
        private readonly EntityManagerInterface $em,
        private readonly RewardService $rewardService,
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
            'isEligible' => $this->rewardService->isEligibleForReward($user),
        ]);
    }

    #[Route('/verifier-recompense', name: 'check_reward', methods: ['POST'])]
    public function checkReward(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('check_reward', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Requête invalide.');
            return $this->redirectToRoute('front_dashboard');
        }

        if (!$user->getNumTel()) {
            $this->addFlash('warning', 'Aucun numéro de téléphone enregistré sur votre compte. Mettez à jour votre profil.');
            return $this->redirectToRoute('front_dashboard');
        }

        if (!$this->rewardService->isEligibleForReward($user)) {
            $this->addFlash('info', 'Vous n\'êtes pas encore éligible à une récompense. Respectez votre budget et vos seuils de catégories.');
            return $this->redirectToRoute('front_dashboard');
        }

        if ($this->rewardService->grantReward($user)) {
            $this->addFlash('success', '🎉 Félicitations ! Un SMS avec votre code promo a été envoyé au ' . $user->getNumTel());
        } else {
            $this->addFlash('error', 'Une erreur est survenue lors de l\'envoi du SMS. Vérifiez votre numéro de téléphone.');
        }

        return $this->redirectToRoute('front_dashboard');
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
    public function module(string $slug, Request $request): Response
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

        if ($slug === 'wallet') {
            return $this->redirectToRoute('front_wallet_dashboard');
        }

        if ($slug === 'publications') {
            $search = trim((string) $request->query->get('keyword', ''));
            $category = trim((string) $request->query->get('category', '')) ?: null;
            $sort = (string) $request->query->get('sort', 'recentes');

            $publications = $this->publicationRepository->findPublishedWithStats(
                $category,
                $search,
                $sort,
            );

            $categories = $this->publicationRepository->getDistinctCategories();

            return $this->render('front/client/publications.html.twig', [
                'module' => self::FRONT_MODULES[$slug],
                'publications' => $publications,
                'categories' => $categories,
                'searchTerm' => $search,
                'selectedCategory' => $category,
                'sortBy' => $sort,
            ]);
        }

        if ($slug === 'budget') {
            $categories = $this->categorieRepository->findBy([], ['nomCategorie' => 'ASC']);
            $budgetStats = [];
            $totalBudget = 0.0;
            $totalSpent = 0.0;
            $activeAlerts = 0;
            $totalItems = 0;

            $weeklyLabels = [
                1 => 'Lun',
                2 => 'Mar',
                3 => 'Mer',
                4 => 'Jeu',
                5 => 'Ven',
                6 => 'Sam',
                7 => 'Dim',
            ];
            $weeklyActivity = array_fill_keys(array_values($weeklyLabels), 0);

            foreach ($categories as $categorie) {
                $spent = $this->itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
                $itemCount = $categorie->getItems()->count();
                $alerts = array_values(array_filter(
                    $categorie->getAlertes()->toArray(),
                    static fn (Alerte $alerte): bool => (bool) $alerte->getActive()
                ));

                foreach ($alerts as $alerte) {
                    $dayIndex = (int) $alerte->getCreatedAt()->format('N');
                    if (isset($weeklyLabels[$dayIndex])) {
                        $weeklyActivity[$weeklyLabels[$dayIndex]]++;
                    }
                }

                $budget = $categorie->getBudgetPrevu();
                $usage = $budget > 0 ? ($spent / $budget) * 100 : 0;
                $remaining = $budget - $spent;
                $alertCount = count($alerts);

                if ($usage >= 100 || $alertCount > 0) {
                    $status = 'danger';
                    $statusLabel = 'Depassement';
                } elseif ($usage >= 80) {
                    $status = 'warning';
                    $statusLabel = 'Alerte proche';
                } else {
                    $status = 'ok';
                    $statusLabel = 'Sous controle';
                }

                $budgetStats[] = [
                    'categorie' => $categorie,
                    'spent' => $spent,
                    'budget' => $budget,
                    'usage' => $usage,
                    'remaining' => $remaining,
                    'itemCount' => $itemCount,
                    'alertCount' => $alertCount,
                    'status' => $status,
                    'statusLabel' => $statusLabel,
                ];

                $totalBudget += $budget;
                $totalSpent += $spent;
                $activeAlerts += $alertCount;
                $totalItems += $itemCount;
            }

            usort($budgetStats, static fn (array $left, array $right): int => $right['usage'] <=> $left['usage']);

            $globalUsage = $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0;
            $remainingBudget = $totalBudget - $totalSpent;
            $topCategories = array_slice($budgetStats, 0, 3);
            $latestAlerts = $this->em->getRepository(Alerte::class)->findBy([], ['createdAt' => 'DESC'], 5);

            $insight = $topCategories !== []
                ? sprintf(
                    'La categorie %s concentre actuellement %.1f%% du budget utilise. Gardez un oeil prioritaire sur ce poste.',
                    $topCategories[0]['categorie']->getNomCategorie(),
                    $topCategories[0]['usage']
                )
                : 'Commencez par definir des categories et des depenses pour activer votre centre de pilotage budgetaire.';

            return $this->render('front/client/budget.html.twig', [
                'module' => self::FRONT_MODULES[$slug],
                'budgetStats' => $budgetStats,
                'topCategories' => $topCategories,
                'latestAlerts' => $latestAlerts,
                'weeklyActivity' => $weeklyActivity,
                'totalBudget' => $totalBudget,
                'totalSpent' => $totalSpent,
                'remainingBudget' => $remainingBudget,
                'globalUsage' => $globalUsage,
                'activeAlerts' => $activeAlerts,
                'trackedCategories' => count($categories),
                'totalItems' => $totalItems,
                'insight' => $insight,
            ]);
        }

        return $this->render('front/client/module.html.twig', [
            'module' => self::FRONT_MODULES[$slug],
        ]);
    }

    #[Route('/publications/{id}', name: 'publications_view', methods: ['GET', 'POST'])]
    public function viewPublication(Publication $publication, Request $request): Response
    {
        $comment = new \App\Entity\User\Feedback();
        $commentForm = $this->createForm(PublicationCommentType::class, $comment, [
            'action' => $this->generateUrl('front_publications_view', ['id' => $publication->getId()]),
        ]);
        $commentForm->handleRequest($request);

        if ($commentForm->isSubmitted() && $commentForm->isValid()) {
            $rating = (int) $commentForm->get('rating')->getData();
            $comment->setPublication($publication)
                ->setUser($this->getUser())
                ->setDateFeedback(new \DateTime())
                ->setTypeReaction('RATING_' . $rating);

            $this->em->persist($comment);
            $this->em->flush();

            $this->addFlash('success', 'Votre avis a bien été pris en compte.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $feedbacks = $publication->getFeedbacks()->toArray();
        $likes = count(array_filter($feedbacks, static fn($feedback) => $feedback->getTypeReaction() === 'LIKE'));
        $dislikes = count(array_filter($feedbacks, static fn($feedback) => $feedback->getTypeReaction() === 'DISLIKE'));
        $ratings = array_map(
            static fn($feedback) => (int) substr($feedback->getTypeReaction(), 7),
            array_filter($feedbacks, static fn($feedback) => str_starts_with((string) $feedback->getTypeReaction(), 'RATING_'))
        );
        $averageRating = $ratings ? round(array_sum($ratings) / count($ratings), 1) : null;
        $comments = array_filter($feedbacks, static fn($feedback) => $feedback->getCommentaire() !== null && $feedback->getCommentaire() !== '');

        return $this->render('front/client/publication_detail.html.twig', [
            'module' => self::FRONT_MODULES['publications'],
            'publication' => $publication,
            'commentForm' => $commentForm->createView(),
            'likes' => $likes,
            'dislikes' => $dislikes,
            'averageRating' => $averageRating,
            'comments' => $comments,
        ]);
    }

    #[Route('/publications/{id}/reaction/{reaction}', name: 'publications_react', methods: ['POST'])]
    public function reactPublication(Publication $publication, string $reaction, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('publication_react_' . $publication->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $reaction = strtoupper($reaction);
        if (!in_array($reaction, ['LIKE', 'DISLIKE'], true)) {
            $this->addFlash('error', 'Réaction invalide.');

            return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
        }

        $feedback = new \App\Entity\User\Feedback();
        $feedback->setPublication($publication)
            ->setUser($this->getUser())
            ->setDateFeedback(new \DateTime())
            ->setTypeReaction($reaction);

        $this->em->persist($feedback);
        $this->em->flush();

        $this->addFlash('success', sprintf('Votre %s a bien été enregistré.', strtolower($reaction)));

        return $this->redirectToRoute('front_publications_view', ['id' => $publication->getId()]);
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
