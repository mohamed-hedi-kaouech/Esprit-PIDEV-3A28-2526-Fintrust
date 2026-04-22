<?php

namespace App\Controller\Admin;

use App\Entity\User\User;
use App\Exception\UserDeletionException;
use App\Form\Admin\AdminCreateClientFormType;
use App\Form\Admin\AdminUserEditFormType;
use App\Repository\KycRepository;
use App\Repository\UserRepository;
use App\Service\ExportService;
use App\Service\KycService;
use App\Service\NotificationService;
use App\Service\QrCodeService;
use App\Service\UserService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Contrôleur Admin — Gestion des utilisateurs (BackOffice)
 *
 * CRUD complet : liste, édition, suppression, activation, suspension.
 * Recherche, filtres, tri, pagination, export CSV/PDF, QR code, notifications.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/utilisateurs', name: 'admin_user_')]
class UserController extends AbstractController
{
    /** Nombre d'utilisateurs par page */
    private const PAGE_SIZE = 15;

    public function __construct(
        private readonly UserRepository      $userRepository,
        private readonly KycRepository       $kycRepository,
        private readonly UserService         $userService,
        private readonly ExportService       $exportService,
        private readonly KycService          $kycService,
        private readonly NotificationService $notificationService,
        private readonly QrCodeService       $qrCodeService,
        private readonly ValidatorInterface  $validator,
    ) {}

    // =========================================================================
    // LISTE — Recherche + Filtres + Tri + Pagination
    // =========================================================================

    /**
     * Liste paginée des utilisateurs avec filtres et tri dynamique.
     *
     * Paramètres GET : search, role, status, kycStatus, sort, dir, page
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        // Récupération des filtres depuis la query string
        $filters = [
            'search'    => $request->query->get('search', ''),
            'role'      => $request->query->get('role', ''),
            'status'    => $request->query->get('status', ''),
            'kycStatus' => $request->query->get('kycStatus', ''),
            'sort'      => $request->query->get('sort', 'createdAt'),
            'dir'       => $request->query->get('dir', 'DESC'),
        ];

        $page = max(1, (int) $request->query->get('page', 1));

        // QueryBuilder filtré + pagination via Doctrine Paginator
        $qb        = $this->userRepository->createFilteredQueryBuilder($filters);
        $statsQb   = clone $qb;
        $paginator = new Paginator(
            $qb->setFirstResult(($page - 1) * self::PAGE_SIZE)
               ->setMaxResults(self::PAGE_SIZE)
        );

        $total = count($paginator);
        $pages = (int) ceil($total / self::PAGE_SIZE);
        /** @var User[] $filteredUsers */
        $filteredUsers = $statsQb->getQuery()->getResult();
        $userStats = $this->buildUserStats($filteredUsers);

        return $this->render('admin/users/list.html.twig', [
            'users'      => $paginator,
            'filters'    => $filters,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'userStats'  => $userStats,
        ]);
    }

    #[Route('/ajouter', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $user = new User();
        $user->setStatus(User::STATUS_ACTIF);

        $form = $this->createForm(AdminCreateClientFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $this->userService->createClientByAdmin($user, $plainPassword);
            $this->kycService->synchronizeApprovedUserWallet($user);

            $this->notificationService->notify(
                $user,
                'Votre compte FinTrust a été créé par un administrateur. Vous pouvez maintenant vous connecter.',
                'SUCCESS'
            );

            $this->addFlash('success', "Le client {$user->getFullName()} a été ajouté avec succès.");

            return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/create.html.twig', [
            'form' => $form,
        ]);
    }

    // =========================================================================
    // ÉDITION
    // =========================================================================

    /**
     * Formulaire d'édition d'un utilisateur (rôle, statut, infos personnelles).
     * Validation Symfony côté serveur.
     */
    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        $previousKycStatus = $user->getKycStatus();
        $form = $this->createForm(AdminUserEditFormType::class, $user);
        $form->handleRequest($request);
        $kyc = $this->kycRepository->findLatestByUser($user);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($kyc && $user->getKycStatus() !== null) {
                $kyc->setStatut($user->getKycStatus());
            }

            $plainPassword = $form->get('plainPassword')->getData();
            $this->userService->updateProfile($user, $plainPassword ?: null);
            $this->kycService->synchronizeApprovedUserWallet($user);

            if ($previousKycStatus !== User::KYC_APPROUVE && $user->getKycStatus() === User::KYC_APPROUVE) {
                $this->notificationService->notifyKycApproved($user);
            }
            $this->addFlash('success', "L'utilisateur {$user->getFullName()} a été mis à jour.");
            return $this->redirectToRoute('admin_user_list');
        }

        $qrUrl = $user->getQrToken()
            ? $this->qrCodeService->getQrImageUrl($user->getQrToken(), $request->getSchemeAndHttpHost())
            : null;

        return $this->render('admin/users/edit.html.twig', [
            'form'  => $form,
            'user'  => $user,
            'kyc'   => $kyc,
            'qrUrl' => $qrUrl,
        ]);
    }

    // =========================================================================
    // SUPPRESSION
    // =========================================================================

    /**
     * Supprime un utilisateur (POST uniquement, protection CSRF).
     */
    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_user_list');
        }

        $name = $user->getFullName();

        try {
            $this->userService->deleteUser($user);
            $this->addFlash('success', "L'utilisateur « {$name} » a été supprimé définitivement.");
        } catch (UserDeletionException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('admin_user_list');
    }

    // =========================================================================
    // ACTIVATION / SUSPENSION
    // =========================================================================

    /**
     * Active le compte d'un utilisateur.
     */
    #[Route('/{id}/activer', name: 'activate', methods: ['POST'])]
    public function activate(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('activate_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_user_list');
        }

        $this->userService->activateUser($user);
        $this->notificationService->notify($user, 'Votre compte FinTrust a été activé par un administrateur.', 'SUCCESS');
        $this->addFlash('success', "{$user->getFullName()} est maintenant actif.");

        return $this->redirectToRoute('admin_user_list');
    }

    /**
     * Suspend le compte d'un utilisateur.
     */
    #[Route('/{id}/suspendre', name: 'suspend', methods: ['POST'])]
    public function suspend(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('suspend_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_user_list');
        }

        $this->userService->suspendUser($user);
        $this->notificationService->notify($user, 'Votre compte a été suspendu. Contactez le support FinTrust.', 'WARNING');
        $this->addFlash('warning', "{$user->getFullName()} a été suspendu.");

        return $this->redirectToRoute('admin_user_list');
    }

    // =========================================================================
    // NOTIFICATION MANUELLE
    // =========================================================================

    /**
     * Envoie une notification interne à un utilisateur depuis l'admin.
     */
    #[Route('/{id}/notifier', name: 'notify', methods: ['POST'])]
    public function notify(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notify_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_user_list');
        }

        $message = trim($request->request->get('message', ''));
        $type = strtoupper((string) $request->request->get('type', 'INFO'));
        $allowedTypes = ['INFO', 'SUCCESS', 'WARNING', 'ERROR'];
        $type = in_array($type, $allowedTypes, true) ? $type : 'INFO';
        $errors = $this->validator->validate($message, [
            new Assert\NotBlank(['message' => 'Le message ne peut pas etre vide.']),
            new Assert\Length([
                'min' => 5,
                'minMessage' => 'Le message doit contenir au moins {{ limit }} caracteres.',
                'max' => 500,
                'maxMessage' => 'Le message ne peut pas depasser {{ limit }} caracteres.',
            ]),
        ]);

        if (count($errors) === 0) {
            $this->notificationService->notify($user, $message, $type);
            $this->addFlash('success', 'Notification envoyée à ' . $user->getFullName());
        } else {
            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/notifier-client', name: 'notify_client', methods: ['POST'])]
    public function quickNotify(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notify_client', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_dashboard');
        }

        $userId = (int) $request->request->get('user_id');
        $message = trim((string) $request->request->get('message', ''));
        $type = strtoupper((string) $request->request->get('type', 'INFO'));

        $user = $this->userRepository->find($userId);
        $allowedTypes = ['INFO', 'SUCCESS', 'WARNING', 'ERROR'];
        $type = in_array($type, $allowedTypes, true) ? $type : 'INFO';

        if (!$user || $user->getRole() !== User::ROLE_CLIENT) {
            $this->addFlash('error', 'Client introuvable.');
            return $this->redirectToRoute('admin_dashboard');
        }

        $errors = $this->validator->validate($message, [
            new Assert\NotBlank(['message' => 'Le message ne peut pas etre vide.']),
            new Assert\Length([
                'min' => 5,
                'minMessage' => 'Le message doit contenir au moins {{ limit }} caracteres.',
                'max' => 500,
                'maxMessage' => 'Le message ne peut pas depasser {{ limit }} caracteres.',
            ]),
        ]);

        if (count($errors) > 0) {
            $this->addFlash('error', (string) $errors[0]->getMessage());
            return $this->redirectToRoute('admin_dashboard');
        }

        $this->notificationService->notify($user, $message, $type);
        $this->addFlash('success', 'Notification envoyée à ' . $user->getFullName() . '.');

        return $this->redirectToRoute('admin_dashboard');
    }

    // =========================================================================
    // EXPORT CSV / PDF
    // =========================================================================

    /**
     * Exporte la liste filtrée en CSV (téléchargement direct).
     */
    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): Response
    {
        $users = $this->userRepository
            ->createFilteredQueryBuilder($this->getFilters($request))
            ->getQuery()
            ->getResult();

        return $this->exportService->exportUsersCsv($users);
    }

    /**
     * Exporte la liste filtrée en HTML imprimable (PDF via window.print).
     */
    #[Route('/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $users = $this->userRepository
            ->createFilteredQueryBuilder($this->getFilters($request))
            ->getQuery()
            ->getResult();

        return $this->exportService->exportUsersPdfHtml($users);
    }

    // =========================================================================
    // HELPERS PRIVÉS
    // =========================================================================

    /** Extrait les filtres de la requête GET. */
    private function getFilters(Request $request): array
    {
        return [
            'search'    => $request->query->get('search', ''),
            'role'      => $request->query->get('role', ''),
            'status'    => $request->query->get('status', ''),
            'kycStatus' => $request->query->get('kycStatus', ''),
            'sort'      => $request->query->get('sort', 'createdAt'),
            'dir'       => $request->query->get('dir', 'DESC'),
        ];
    }

    /**
     * @param User[] $users
     * @return array{
     *   total:int,
     *   active:int,
     *   admins:int,
     *   kycApproved:int,
     *   newThisMonth:int,
     *   growthRate:float,
     *   latestSignup:?string,
     *   activationRate:float,
     *   approvalRate:float,
     *   monthlyLabels:array<int,string>,
     *   monthlyTotal:array<int,int>,
     *   monthlyActive:array<int,int>,
     *   monthlyKycApproved:array<int,int>,
     *   roleBreakdown:array{clients:int,admins:int},
     *   statusBreakdown:array{active:int,pending:int,suspended:int},
     *   kycBreakdown:array{approved:int,pending:int,refused:int}
     * }
     */
    private function buildUserStats(array $users): array
    {
        $total = count($users);
        $active = 0;
        $admins = 0;
        $kycApproved = 0;
        $newThisMonth = 0;
        $latestSignup = null;
        $clients = 0;
        $pending = 0;
        $suspended = 0;
        $kycPending = 0;
        $kycRefused = 0;
        $lastMonthCount = 0;

        $monthCursor = new \DateTimeImmutable('first day of this month -5 months');
        $monthlyMap = [];
        for ($i = 0; $i < 6; $i++) {
            $key = $monthCursor->format('Y-m');
            $monthlyMap[$key] = [
                'label' => $monthCursor->format('M Y'),
                'total' => 0,
                'active' => 0,
                'kycApproved' => 0,
            ];
            $monthCursor = $monthCursor->modify('+1 month');
        }

        $currentMonth = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
        $previousMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m');

        foreach ($users as $user) {
            $createdAt = $user->getCreatedAt();
            $monthKey = $createdAt->format('Y-m');

            if ($monthKey === $currentMonth) {
                $newThisMonth++;
            }
            if ($monthKey === $previousMonth) {
                $lastMonthCount++;
            }

            if (isset($monthlyMap[$monthKey])) {
                $monthlyMap[$monthKey]['total']++;
                if ($user->getStatus() === User::STATUS_ACTIF) {
                    $monthlyMap[$monthKey]['active']++;
                }
                if ($user->getKycStatus() === User::KYC_APPROUVE) {
                    $monthlyMap[$monthKey]['kycApproved']++;
                }
            }

            if ($latestSignup === null || $createdAt > new \DateTimeImmutable($latestSignup)) {
                $latestSignup = $createdAt->format('d/m/Y');
            }

            if ($user->getRole() === User::ROLE_ADMIN) {
                $admins++;
            } else {
                $clients++;
            }

            if ($user->getStatus() === User::STATUS_ACTIF) {
                $active++;
            } elseif ($user->getStatus() === User::STATUS_EN_ATTENTE) {
                $pending++;
            } else {
                $suspended++;
            }

            if ($user->getKycStatus() === User::KYC_APPROUVE) {
                $kycApproved++;
            } elseif ($user->getKycStatus() === User::KYC_EN_ATTENTE) {
                $kycPending++;
            } elseif ($user->getKycStatus() === User::KYC_REFUSE) {
                $kycRefused++;
            }
        }

        $growthRate = $lastMonthCount > 0
            ? round((($newThisMonth - $lastMonthCount) / $lastMonthCount) * 100, 1)
            : ($newThisMonth > 0 ? 100.0 : 0.0);

        $activationRate = $total > 0 ? round(($active / $total) * 100, 1) : 0.0;
        $approvalRate = $total > 0 ? round(($kycApproved / $total) * 100, 1) : 0.0;

        return [
            'total' => $total,
            'active' => $active,
            'admins' => $admins,
            'kycApproved' => $kycApproved,
            'newThisMonth' => $newThisMonth,
            'growthRate' => $growthRate,
            'latestSignup' => $latestSignup,
            'activationRate' => $activationRate,
            'approvalRate' => $approvalRate,
            'monthlyLabels' => array_values(array_map(static fn(array $item) => $item['label'], $monthlyMap)),
            'monthlyTotal' => array_values(array_map(static fn(array $item) => $item['total'], $monthlyMap)),
            'monthlyActive' => array_values(array_map(static fn(array $item) => $item['active'], $monthlyMap)),
            'monthlyKycApproved' => array_values(array_map(static fn(array $item) => $item['kycApproved'], $monthlyMap)),
            'roleBreakdown' => [
                'clients' => $clients,
                'admins' => $admins,
            ],
            'statusBreakdown' => [
                'active' => $active,
                'pending' => $pending,
                'suspended' => $suspended,
            ],
            'kycBreakdown' => [
                'approved' => $kycApproved,
                'pending' => $kycPending,
                'refused' => $kycRefused,
            ],
        ];
    }
}

