<?php

namespace App\Command;

use App\Entity\User\User;
use App\Service\BehavioralProfileService;
use App\Service\QrCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Créer un utilisateur administrateur',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly QrCodeService $qrCodeService,
        private readonly BehavioralProfileService $behavioralProfileService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = 'admin@fintrust.tn';
        $password = 'admin';
        $nom = 'Administrateur';
        $prenom = 'FinTrust';

        // Vérifier si l'admin existe déjà
        $existingAdmin = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingAdmin) {
            $io->warning("Un utilisateur avec l'email {$email} existe déjà.");
            return Command::FAILURE;
        }

        // Créer l'admin
        $admin = new User();
        $admin->setEmail($email);
        $admin->setNom($nom);
        $admin->setPrenom($prenom);
        $admin->setRole(User::ROLE_ADMIN);
        $admin->setStatus(User::STATUS_ACTIF);
        $admin->setCreatedAt(new \DateTime());
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $admin->setQrToken($this->qrCodeService->generateToken());
        $admin->setIsVerified(true);
        $admin->setEmailVerificationCode(null);
        $admin->setEmailVerificationExpiresAt(null);
        $admin->setEmailVerifiedAt(new \DateTime());

        $this->em->persist($admin);
        $this->em->flush();

        // Initialiser le profil comportemental
        $this->behavioralProfileService->refreshUserBehavior($admin);

        $io->success("Admin créé avec succès !");
        $io->table(
            ['Email', 'Mot de passe', 'Rôle'],
            [[$email, $password, 'ADMIN']]
        );

        return Command::SUCCESS;
    }
}
