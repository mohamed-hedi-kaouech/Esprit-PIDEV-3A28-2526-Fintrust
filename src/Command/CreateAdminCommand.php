<?php

namespace App\Command;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Creer un utilisateur administrateur',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
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

        $existingAdmin = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingAdmin) {
            $io->warning("Un utilisateur avec l'email {$email} existe deja.");

            return Command::FAILURE;
        }

        $admin = new User();
        $admin->setEmail($email);
        $admin->setNom($nom);
        $admin->setPrenom($prenom);
        $admin->setRole(User::ROLE_ADMIN);
        $admin->setStatus(User::STATUS_ACTIF);
        $admin->setCreatedAt(new \DateTime());
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $admin->setIsVerified(true);

        $this->em->persist($admin);
        $this->em->flush();

        $io->success('Admin cree avec succes !');
        $io->table(
            ['Email', 'Mot de passe', 'Role'],
            [[$email, $password, 'ADMIN']]
        );

        return Command::SUCCESS;
    }
}
