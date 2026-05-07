<?php

namespace App\Tests\Service;

use App\Entity\User\User;
use App\Repository\UserRepository;
use App\Service\BehavioralProfileService;
use App\Service\NotificationService;
use App\Service\QrCodeService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $passwordHasher;
    private QrCodeService $qrCodeService;
    private BehavioralProfileService $behavioralProfileService;
    private NotificationService $notificationService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->qrCodeService = $this->createMock(QrCodeService::class);
        $this->behavioralProfileService = $this->createMock(BehavioralProfileService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
    }

    public function testValidateIdentityThrowsExceptionWhenNameOrFirstNameIsEmpty(): void
    {
        $service = $this->createService();
        $user = (new User())
            ->setNom('')
            ->setPrenom('Ines')
            ->setEmail('ines@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom ou le prenom est obligatoire.');

        $service->validateIdentity($user);
    }

    public function testValidateEmailThrowsExceptionWhenEmailIsInvalid(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email invalide');

        $service->validateEmail('test@');
    }

    public function testValidatePasswordThrowsExceptionWhenPasswordIsTooShort(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le mot de passe doit contenir au moins 8 caracteres.');

        $service->validatePassword('short');
    }

    public function testCanActivateReturnsFalseWhenKycIsRefused(): void
    {
        $service = $this->createService();
        $user = (new User())
            ->setKycStatus(User::KYC_REFUSE)
            ->setStatus(User::STATUS_EN_ATTENTE);

        self::assertFalse($service->canActivate($user));
        self::assertFalse($user->isActive());
    }

    public function testCanAccessAdminReturnsTrueForAdminUser(): void
    {
        $service = $this->createService();
        $user = (new User())->setRole(User::ROLE_ADMIN);

        self::assertTrue($service->canAccessAdmin($user));
    }

    private function createService(): UserService
    {
        return new UserService(
            $this->entityManager,
            $this->userRepository,
            $this->passwordHasher,
            $this->qrCodeService,
            $this->behavioralProfileService,
            $this->notificationService,
            dirname(__DIR__, 2)
        );
    }
}
