<?php

namespace App\EventSubscriber;

use App\Entity\User\Client\Kyc;
use App\Entity\User\User;
use App\Repository\KycRepository;
use App\Repository\UserRepository;
use CalendarBundle\CalendarEvents;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\CalendarEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class AdminCalendarSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly KycRepository $kycRepository,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CalendarEvents::SET_DATA => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(CalendarEvent $calendar): void
    {
        $filters = $calendar->getFilters();
        if (($filters['calendar-id'] ?? null) !== 'admin-calendar') {
            return;
        }

        $this->addClientRegistrations($calendar);
        $this->addKycSubmissions($calendar);
        $this->addClientAdviceAlerts($calendar);
        $this->addWeeklyAdminRoutines($calendar);
    }

    private function addClientRegistrations(CalendarEvent $calendar): void
    {
        /** @var User[] $clients */
        $clients = $this->userRepository->findBy(
            ['role' => User::ROLE_CLIENT],
            ['createdAt' => 'DESC'],
            40
        );

        foreach ($clients as $client) {
            $event = new Event(
                'Inscription: ' . $client->getFullName(),
                $client->getCreatedAt(),
                null,
                null,
                [
                    'backgroundColor' => '#2563eb',
                    'borderColor' => '#1d4ed8',
                    'textColor' => '#ffffff',
                    'url' => $this->router->generate('admin_user_edit', ['id' => $client->getId()]),
                    'extendedProps' => [
                        'type' => 'Client',
                        'description' => $client->getEmail(),
                    ],
                ]
            );
            $calendar->addEvent($event);
        }
    }

    private function addKycSubmissions(CalendarEvent $calendar): void
    {
        /** @var Kyc[] $kycList */
        $kycList = $this->kycRepository->findRecentActivity(50);

        foreach ($kycList as $kyc) {
            $status = $kyc->getStatut();
            $colors = $this->kycColors($status);
            $event = new Event(
                'KYC ' . strtolower($status) . ': ' . $kyc->getUser()->getFullName(),
                $kyc->getDateSubmission(),
                null,
                null,
                [
                    'backgroundColor' => $colors['background'],
                    'borderColor' => $colors['border'],
                    'textColor' => '#ffffff',
                    'url' => $this->router->generate('admin_kyc_view', ['id' => $kyc->getId()]),
                    'extendedProps' => [
                        'type' => 'KYC',
                        'description' => 'Statut ' . $status,
                    ],
                ]
            );
            $calendar->addEvent($event);
        }
    }

    private function addClientAdviceAlerts(CalendarEvent $calendar): void
    {
        /** @var User[] $clients */
        $clients = $this->userRepository->findBy(
            ['role' => User::ROLE_CLIENT],
            ['riskScore' => 'DESC', 'createdAt' => 'DESC'],
            35
        );

        $slot = 0;
        foreach ($clients as $client) {
            $advice = $this->buildClientAdvice($client);
            if ($advice === null) {
                continue;
            }

            $scheduledAt = (new \DateTimeImmutable('today'))
                ->modify('+' . ($slot % 7) . ' days')
                ->setTime(10 + (int) floor(($slot % 6) / 2), ($slot % 2) * 30);
            $slot++;

            $calendar->addEvent(new Event(
                'Conseil: ' . $client->getFullName(),
                $scheduledAt,
                $scheduledAt->modify('+30 minutes'),
                null,
                [
                    'backgroundColor' => $advice['background'],
                    'borderColor' => $advice['border'],
                    'textColor' => '#ffffff',
                    'url' => $this->router->generate('admin_user_edit', ['id' => $client->getId()]),
                    'extendedProps' => [
                        'type' => 'Alerte conseil',
                        'description' => $advice['description'],
                        'priority' => $advice['priority'],
                    ],
                ]
            ));
        }
    }

    private function addWeeklyAdminRoutines(CalendarEvent $calendar): void
    {
        $today = new \DateTimeImmutable('today');
        for ($week = -2; $week <= 4; $week++) {
            $monday = $today->modify(($week * 7) . ' days')->modify('monday this week')->setTime(9, 0);
            $calendar->addEvent(new Event(
                'Controle conformite hebdo',
                $monday,
                $monday->modify('+45 minutes'),
                null,
                [
                    'backgroundColor' => '#f59e0b',
                    'borderColor' => '#d97706',
                    'textColor' => '#111827',
                    'extendedProps' => [
                        'type' => 'Routine',
                        'description' => 'Revue rapide des dossiers, alertes et risques.',
                    ],
                ]
            ));
        }
    }

    /**
     * @return array{description:string,priority:string,background:string,border:string}|null
     */
    private function buildClientAdvice(User $client): ?array
    {
        if ($client->isCriticalRisk() || $client->getFraudScore() >= 70) {
            return [
                'description' => 'Verifier le profil, controler les signaux fraude et contacter le client avant validation.',
                'priority' => 'Urgent',
                'background' => '#dc2626',
                'border' => '#991b1b',
            ];
        }

        if ($client->isAtRisk() || $client->getRiskScore() >= 65) {
            return [
                'description' => 'Analyser le score risque, revoir les operations recentes et ajouter une note admin.',
                'priority' => 'Haute',
                'background' => '#f97316',
                'border' => '#c2410c',
            ];
        }

        if ($client->getKycStatus() === User::KYC_EN_ATTENTE) {
            return [
                'description' => 'Traiter le dossier KYC, verifier les documents et donner une decision claire.',
                'priority' => 'KYC',
                'background' => '#f59e0b',
                'border' => '#d97706',
            ];
        }

        if ($client->getKycStatus() === User::KYC_REFUSE) {
            return [
                'description' => 'Envoyer une consigne precise pour corriger le dossier refuse.',
                'priority' => 'Suivi',
                'background' => '#be123c',
                'border' => '#9f1239',
            ];
        }

        if ($client->getStatus() === User::STATUS_EN_ATTENTE) {
            return [
                'description' => 'Relancer le client ou activer le compte si les informations sont conformes.',
                'priority' => 'Activation',
                'background' => '#2563eb',
                'border' => '#1d4ed8',
            ];
        }

        if ($client->getStatus() === User::STATUS_SUSPENDU) {
            return [
                'description' => 'Revoir la raison de suspension et decider maintien, activation ou contact client.',
                'priority' => 'Controle',
                'background' => '#7c3aed',
                'border' => '#6d28d9',
            ];
        }

        return null;
    }

    /**
     * @return array{background:string,border:string}
     */
    private function kycColors(string $status): array
    {
        return match ($status) {
            Kyc::STATUT_APPROUVE => ['background' => '#16a34a', 'border' => '#15803d'],
            Kyc::STATUT_REFUSE => ['background' => '#dc2626', 'border' => '#b91c1c'],
            default => ['background' => '#f59e0b', 'border' => '#d97706'],
        };
    }
}
