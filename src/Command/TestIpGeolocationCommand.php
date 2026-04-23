<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:test-ip-geolocation',
    description: 'Teste rapidement l API externe de geolocalisation IP.',
)]
class TestIpGeolocationCommand extends Command
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('ip', InputArgument::OPTIONAL, 'Adresse IP a tester, ou "auto" pour IP publique actuelle', 'auto');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ip = (string) $input->getArgument('ip');
        $auto = strtolower($ip) === 'auto';
        if (!$auto && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $output->writeln('<error>IP invalide.</error>');

            return Command::INVALID;
        }

        try {
            $url = $auto
                ? 'http://ip-api.com/json/'
                : 'http://ip-api.com/json/' . rawurlencode($ip);
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'fields' => 'status,message,country,city,query,isp',
                    'lang' => 'fr',
                ],
                'timeout' => 5,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $exception) {
            $output->writeln('<error>Erreur API: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (($data['status'] ?? null) !== 'success') {
            $output->writeln('<error>Geolocalisation impossible: ' . ($data['message'] ?? 'reponse invalide') . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>API geolocalisation OK</info>');
        $output->writeln('IP: ' . ($data['query'] ?? $ip));
        $output->writeln('Pays: ' . ($data['country'] ?? 'inconnu'));
        $output->writeln('Ville: ' . ($data['city'] ?? 'inconnue'));
        $output->writeln('ISP: ' . ($data['isp'] ?? 'inconnu'));

        return Command::SUCCESS;
    }
}
