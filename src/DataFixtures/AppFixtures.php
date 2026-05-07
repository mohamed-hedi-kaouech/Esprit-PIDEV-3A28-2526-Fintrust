<?php

namespace App\DataFixtures;

use App\Entity\User\User;
use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $clients = [
            [
                'prenom' => 'Mariem',
                'nom' => 'Ben Salah',
                'email' => 'mariem.bensalah@fintrust.test',
                'phone' => '+216 22 184 905',
                'balance' => '8450.00',
                'overdraft' => '500.00',
                'segment' => User::SEGMENT_VIP,
                'risk' => User::RISK_LOW,
                'frequency' => 1.35,
                'average' => 2140.00,
                'created' => '-14 months',
            ],
            [
                'prenom' => 'Said',
                'nom' => 'Trabelsi',
                'email' => 'said.trabelsi@fintrust.test',
                'phone' => '+216 55 321 774',
                'balance' => '1280.50',
                'overdraft' => '300.00',
                'segment' => User::SEGMENT_STANDARD,
                'risk' => User::RISK_MEDIUM,
                'frequency' => 0.72,
                'average' => 685.00,
                'created' => '-9 months',
            ],
            [
                'prenom' => 'Hedi',
                'nom' => 'Kaouech',
                'email' => 'hedi.kaouech@fintrust.test',
                'phone' => '+216 24 765 118',
                'balance' => '15230.75',
                'overdraft' => '1000.00',
                'segment' => User::SEGMENT_VIP,
                'risk' => User::RISK_LOW,
                'frequency' => 1.58,
                'average' => 3400.00,
                'created' => '-22 months',
            ],
            [
                'prenom' => 'Fatma',
                'nom' => 'Hajji',
                'email' => 'fatma.hajji@fintrust.test',
                'phone' => '+216 28 640 915',
                'balance' => '3920.20',
                'overdraft' => '400.00',
                'segment' => User::SEGMENT_STANDARD,
                'risk' => User::RISK_LOW,
                'frequency' => 0.95,
                'average' => 980.00,
                'created' => '-11 months',
            ],
            [
                'prenom' => 'Mourad',
                'nom' => 'Hajji',
                'email' => 'mourad.hajji@fintrust.test',
                'phone' => '+216 29 783 442',
                'balance' => '620.00',
                'overdraft' => '250.00',
                'segment' => User::SEGMENT_AT_RISK,
                'risk' => User::RISK_HIGH,
                'frequency' => 1.10,
                'average' => 1425.00,
                'created' => '-7 months',
            ],
            [
                'prenom' => 'Firas',
                'nom' => 'Hajji',
                'email' => 'firas.hajji@fintrust.test',
                'phone' => '+216 20 447 308',
                'balance' => '2360.90',
                'overdraft' => '300.00',
                'segment' => User::SEGMENT_STANDARD,
                'risk' => User::RISK_MEDIUM,
                'frequency' => 0.82,
                'average' => 730.00,
                'created' => '-6 months',
            ],
            [
                'prenom' => 'Rabiaa',
                'nom' => 'Hsoumii',
                'email' => 'rabiaa.hsoumii@fintrust.test',
                'phone' => '+216 21 905 672',
                'balance' => '9875.30',
                'overdraft' => '750.00',
                'segment' => User::SEGMENT_VIP,
                'risk' => User::RISK_LOW,
                'frequency' => 1.25,
                'average' => 2650.00,
                'created' => '-18 months',
            ],
        ];

        $wallets = [];

        foreach ($clients as $index => $clientData) {
            $user = (new User())
                ->setPrenom($clientData['prenom'])
                ->setNom($clientData['nom'])
                ->setEmail($clientData['email'])
                ->setNumTel($clientData['phone'])
                ->setRole(User::ROLE_CLIENT)
                ->setKycStatus(User::KYC_APPROUVE)
                ->setStatus(User::STATUS_ACTIF)
                ->setCreatedAt(new \DateTime($clientData['created']))
                ->setQrToken(hash('sha256', 'fintrust-test-' . $clientData['email']))
                ->setPreferredLanguage(User::LANGUAGE_FR)
                ->setThemeMode($index % 2 === 0 ? User::THEME_LIGHT : User::THEME_DARK)
                ->setTransactionFrequency($clientData['frequency'])
                ->setAverageTransactionAmount($clientData['average'])
                ->setRiskScore($this->riskScoreFor($clientData['risk']))
                ->setFraudScore($this->fraudScoreFor($clientData['risk']))
                ->setRiskLevel($clientData['risk'])
                ->setClientSegment($clientData['segment'])
                ->setBehaviorUpdatedAt(new \DateTime('-2 days'));

            $user->setPassword($this->passwordHasher->hashPassword($user, 'Password123!'));
            $manager->persist($user);

            $wallet = (new Wallet())
                ->setUser($user)
                ->setNomProprietaire($user->getFullName())
                ->setTelephone($clientData['phone'])
                ->setEmail($clientData['email'])
                ->setCodeAcces((string) (4820 + $index * 137))
                ->setEstActif(true)
                ->setSolde($clientData['balance'])
                ->setPlafondDecouvert($clientData['overdraft'])
                ->setDevise('TND')
                ->setStatut('actif')
                ->setDateCreation(new \DateTime($clientData['created']))
                ->setTentativesEchouees($index === 4 ? 1 : 0)
                ->setEstBloque(false);

            $manager->persist($wallet);
            $wallets[] = ['wallet' => $wallet, 'user' => $user, 'data' => $clientData];
        }

        $manager->flush();

        foreach ($wallets as $entry) {
            /** @var Wallet $wallet */
            $wallet = $entry['wallet'];
            /** @var User $user */
            $user = $entry['user'];

            $wallet->setIdUser($user->getId());
            $this->createTransactions($manager, $wallet, $entry['data']['prenom']);
            $this->createCheques($manager, $wallet, $entry['data']['prenom']);
        }

        $manager->flush();
    }

    private function createTransactions(ObjectManager $manager, Wallet $wallet, string $prenom): void
    {
        $transactions = [
            ['-42 days 09:20', 1850.00, 'depot', 'Virement salaire mensuel'],
            ['-36 days 18:45', 126.40, 'retrait', 'Paiement courses et services'],
            ['-28 days 11:10', 420.00, 'transfert', 'Ref: TRF-TEST-' . $wallet->getIdWallet() . '-001 | Statut: VALIDE | Destinataire: facture STEG | Libelle: Electricite'],
            ['-16 days 14:35', 650.00, 'depot', 'Remboursement client'],
            ['-8 days 20:05', 275.50, 'retrait', 'Retrait DAB centre-ville'],
            ['-2 days 10:15', 95.00, 'transfert', 'Ref: TRF-TEST-' . $wallet->getIdWallet() . '-002 | Statut: VALIDE | Destinataire: ' . $prenom . ' epargne | Libelle: Mise de cote'],
        ];

        foreach ($transactions as [$date, $amount, $type, $description]) {
            $transaction = (new Transaction())
                ->setWallet($wallet)
                ->setMontant($amount)
                ->setType($type)
                ->setDescription($description)
                ->markOccurredAt(new \DateTime($date));

            $manager->persist($transaction);
        }
    }

    private function createCheques(ObjectManager $manager, Wallet $wallet, string $prenom): void
    {
        $cheques = [
            ['-31 days 10:00', '-27 days 15:30', 320.00, 'valide', 'Clinique El Amen', null],
            ['-12 days 12:10', null, 780.00, 'en_attente', 'Societe Services Plus', null],
            ['-5 days 09:40', '-3 days 16:20', 1450.00, $prenom === 'Mourad' ? 'refuse' : 'valide', 'Meubles Carthage', $prenom === 'Mourad' ? 'Solde insuffisant au moment de la presentation' : null],
        ];

        foreach ($cheques as $index => [$emissionDate, $presentationDate, $amount, $status, $beneficiary, $rejectReason]) {
            $cheque = (new Cheque())
                ->setWallet($wallet)
                ->setNumeroCheque(sprintf('CHQ%04d%02d', $wallet->getIdWallet(), $index + 1))
                ->setMontant($amount)
                ->setDateEmission(new \DateTime($emissionDate))
                ->setDatePresentation($presentationDate !== null ? new \DateTime($presentationDate) : null)
                ->setStatut($status)
                ->setBeneficiaire($beneficiary)
                ->setMotifRejet($rejectReason);

            $manager->persist($cheque);
        }
    }

    private function riskScoreFor(string $riskLevel): float
    {
        return match ($riskLevel) {
            User::RISK_HIGH => 73.0,
            User::RISK_MEDIUM => 42.0,
            default => 18.0,
        };
    }

    private function fraudScoreFor(string $riskLevel): float
    {
        return match ($riskLevel) {
            User::RISK_HIGH => 58.0,
            User::RISK_MEDIUM => 24.0,
            default => 7.0,
        };
    }
}
