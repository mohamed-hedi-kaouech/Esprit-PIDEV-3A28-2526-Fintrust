<?php

namespace App\Service;

use App\Dto\InternationalTransferData;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Exception\InternationalTransferException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class InternationalTransferService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly ExchangeRateApiService $exchangeRateApiService,
        private readonly NotificationService $notificationService,
        private readonly WalletAuditService $walletAuditService,
    ) {
    }

    /**
     * @return Wallet[]
     */
    public function findWalletsForUser(User $user): array
    {
        /** @var Wallet[] $wallets */
        $wallets = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        return $wallets;
    }

    /**
     * @return array<string, int>
     */
    public function getWalletChoicesForUser(User $user): array
    {
        $choices = [];

        foreach ($this->findWalletsForUser($user) as $wallet) {
            $label = sprintf(
                '#%d - %s (%s %s)',
                $wallet->getIdWallet(),
                trim((string) $wallet->getNomProprietaire()) !== '' ? trim((string) $wallet->getNomProprietaire()) : 'Wallet client',
                number_format((float) $wallet->getSolde(), 2, ',', ' '),
                mb_strtoupper($wallet->getDevise())
            );

            $choices[$label] = $wallet->getIdWallet();
        }

        return $choices;
    }

    /**
     * @return array{
     *   source_wallet_id:int,
     *   source_wallet_label:string,
     *   source_wallet_balance:float,
     *   source_currency:string,
     *   target_currency:string,
     *   amount:float,
     *   rate:float,
     *   rate_date:string,
     *   provider:string,
     *   converted_amount:float,
     *   fees:float,
     *   total_debit:float,
     *   beneficiary:string,
     *   reference:?string
     * }
     */
    public function buildPreview(User $user, InternationalTransferData $data): array
    {
        $wallet = $this->findOwnedWallet($user, (int) ($data->getSourceWalletId() ?? 0));

        if (!$wallet->getEstActif() || $wallet->getEstBloque()) {
            throw new InternationalTransferException('Le wallet source est inactif ou bloque.');
        }

        $amount = round((float) ($data->getAmount() ?? 0), 2);
        if ($amount <= 0) {
            throw new InternationalTransferException('Le montant doit etre strictement superieur a zero.');
        }

        $sourceCurrency = mb_strtoupper(trim((string) $data->getSourceCurrency()));
        $walletCurrency = mb_strtoupper(trim((string) $wallet->getDevise()));
        if ($sourceCurrency !== $walletCurrency) {
            throw new InternationalTransferException(sprintf(
                'La devise source selectionnee (%s) ne correspond pas a la devise du wallet (%s).',
                $sourceCurrency,
                $walletCurrency
            ));
        }

        $targetCurrency = mb_strtoupper(trim((string) $data->getTargetCurrency()));
        if ($sourceCurrency === $targetCurrency) {
            throw new InternationalTransferException('La devise cible doit etre differente de la devise source.');
        }

        $beneficiary = trim((string) $data->getBeneficiary());
        if ($beneficiary === '') {
            throw new InternationalTransferException('Le beneficiaire est obligatoire.');
        }

        $reference = trim((string) $data->getReference());
        $rateData = $this->exchangeRateApiService->getLatestRate($sourceCurrency, $targetCurrency);
        $convertedAmount = round($amount * (float) $rateData['rate'], 2);
        $fees = $this->calculateSimulatedFees($amount, $sourceCurrency, $targetCurrency);
        $totalDebit = round($amount + $fees, 2);
        $availableBalance = round((float) $wallet->getSolde(), 2);

        if ($availableBalance < $totalDebit) {
            throw new InternationalTransferException(sprintf(
                'Solde insuffisant. Le debit total est de %.2f %s pour un solde disponible de %.2f %s.',
                $totalDebit,
                $sourceCurrency,
                $availableBalance,
                $sourceCurrency
            ));
        }

        return [
            'source_wallet_id' => $wallet->getIdWallet(),
            'source_wallet_label' => $this->formatWalletLabel($wallet),
            'source_wallet_balance' => $availableBalance,
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'amount' => $amount,
            'rate' => (float) $rateData['rate'],
            'rate_date' => (string) $rateData['date'],
            'provider' => (string) $rateData['provider'],
            'converted_amount' => $convertedAmount,
            'fees' => $fees,
            'total_debit' => $totalDebit,
            'beneficiary' => $beneficiary,
            'reference' => $reference !== '' ? $reference : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, InternationalTransferData $data): array
    {
        $wallet = $this->findOwnedWallet($user, (int) ($data->getSourceWalletId() ?? 0));
        $preview = $this->buildPreview($user, $data);
        $createdAt = new \DateTimeImmutable();
        $internalReference = $this->generateTransferReference();
        $status = 'VALIDE';
        $balanceBefore = round((float) $wallet->getSolde(), 2);
        $balanceAfter = round($balanceBefore - (float) $preview['total_debit'], 2);

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'UPDATE wallet SET solde = :solde WHERE id_wallet = :walletId',
                [
                    'solde' => number_format($balanceAfter, 2, '.', ''),
                    'walletId' => $wallet->getIdWallet(),
                ]
            );

            $this->connection->executeStatement(
                'INSERT INTO `transaction` (montant, type, description, date_transaction, id_wallet)
                 VALUES (:montant, :type, :description, :dateTransaction, :walletId)',
                [
                    'montant' => $preview['total_debit'],
                    'type' => 'transfert',
                    'description' => $this->buildDescription($internalReference, $status, $preview),
                    'dateTransaction' => $createdAt->format('Y-m-d H:i:s'),
                    'walletId' => $wallet->getIdWallet(),
                ]
            );

            $transactionId = (int) $this->connection->lastInsertId();
            $this->connection->commit();

            $wallet->setSolde(number_format($balanceAfter, 2, '.', ''));

            $this->notificationService->notifyTransferSent(
                $user,
                $internalReference,
                (float) $preview['amount'],
                (string) $preview['source_currency'],
                (string) $preview['beneficiary'],
                $preview['reference']
            );

            $transfer = array_merge($preview, [
                'id' => $transactionId,
                'reference_code' => $internalReference,
                'status' => $status,
                'created_at' => $createdAt,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            $this->walletAuditService->log('wallet.transfer.international.simulated', [
                'transaction_id' => $transactionId,
                'reference' => $internalReference,
                'sender_user_id' => $user->getId(),
                'source_wallet_id' => $wallet->getIdWallet(),
                'amount' => $preview['amount'],
                'fees' => $preview['fees'],
                'total_debit' => $preview['total_debit'],
                'source_currency' => $preview['source_currency'],
                'target_currency' => $preview['target_currency'],
                'rate' => $preview['rate'],
                'rate_date' => $preview['rate_date'],
                'beneficiary' => $preview['beneficiary'],
                'external_reference' => $preview['reference'],
                'status' => $status,
            ]);

            return $transfer;
        } catch (\Throwable $throwable) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw new InternationalTransferException(
                'Le transfert international simule a echoue: ' . $throwable->getMessage(),
                previous: $throwable
            );
        }
    }

    private function calculateSimulatedFees(float $amount, string $sourceCurrency, string $targetCurrency): float
    {
        $percentage = 0.018;
        $fixedFee = 4.50;

        if (in_array($targetCurrency, ['AED', 'SAR', 'JPY'], true)) {
            $fixedFee += 1.50;
        }

        if ($sourceCurrency !== 'EUR') {
            $percentage += 0.0025;
        }

        return round(max(5.00, ($amount * $percentage) + $fixedFee), 2);
    }

    private function buildDescription(string $internalReference, string $status, array $preview): string
    {
        $parts = [
            'Ref: ' . $internalReference,
            'Statut: ' . $status,
            sprintf('Destinataire: %s', $preview['beneficiary']),
            'Canal: INTERNATIONAL',
            'Libelle: Transfert international',
            sprintf(
                'Montant source: %.2f %s',
                $preview['amount'],
                $preview['source_currency']
            ),
            sprintf('Taux: %.6f', $preview['rate']),
            sprintf(
                'Montant converti: %.2f %s',
                $preview['converted_amount'],
                $preview['target_currency']
            ),
            sprintf(
                'Frais: %.2f %s',
                $preview['fees'],
                $preview['source_currency']
            ),
            sprintf(
                'Debit total: %.2f %s',
                $preview['total_debit'],
                $preview['source_currency']
            ),
            'Date taux: ' . $preview['rate_date'],
            'Provider: ' . $preview['provider'],
        ];

        if (is_string($preview['reference'] ?? null) && trim($preview['reference']) !== '') {
            $parts[] = 'Reference client: ' . trim($preview['reference']);
        }

        return implode(' | ', $parts);
    }

    private function findOwnedWallet(User $user, int $walletId): Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.idWallet = :walletId')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('walletId', $walletId)
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$wallet instanceof Wallet) {
            throw new InternationalTransferException('Le wallet source selectionne est introuvable.');
        }

        return $wallet;
    }

    private function formatWalletLabel(Wallet $wallet): string
    {
        $owner = trim((string) $wallet->getNomProprietaire());

        if ($owner !== '') {
            return sprintf('wallet #%d (%s)', $wallet->getIdWallet(), $owner);
        }

        return sprintf('wallet #%d', $wallet->getIdWallet());
    }

    private function generateTransferReference(): string
    {
        return sprintf('INT-%s-%s', date('YmdHis'), strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
    }
}
