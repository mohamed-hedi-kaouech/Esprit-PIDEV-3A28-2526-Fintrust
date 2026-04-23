<?php

namespace App\Command;

use App\Service\RewardService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-rewards',
    description: 'Check user eligibility and send SMS reward to eligible users.',
)]
class CheckRewardsCommand extends Command
{
    public function __construct(private RewardService $rewardService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $eligibleUsers = $this->rewardService->getEligibleUsers();

        if (empty($eligibleUsers)) {
            $io->success('Aucun utilisateur éligible ce mois-ci.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($eligibleUsers as $user) {
            if ($this->rewardService->grantReward($user)) {
                $count++;
                $io->writeln("✅ SMS envoyé à {$user->getEmail()} ({$user->getNumTel()})");
            } else {
                $io->error("❌ Échec pour {$user->getEmail()}");
            }
        }

        $io->success("SMS envoyés à {$count} utilisateur(s).");

        return Command::SUCCESS;
    }
}
