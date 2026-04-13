<?php

namespace App\Entity\Wallet;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transaction')]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_transaction', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idTransaction;

    #[ORM\Column(name: 'montant', type: 'float')]
    private float $montant;

    #[ORM\Column(name: 'type', type: 'string', length: 20)]
    private string $type;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private string|null $description = null;

    #[ORM\Column(name: 'date_transaction', type: 'datetime')]
    private \DateTimeInterface $dateTransaction;

    #[ORM\Column(name: 'id_wallet', type: 'integer')]
    private int $idWallet;

    // FIX: added ManyToOne relation with inversedBy: 'transactions' to match Wallet#transactions OneToMany
    #[ORM\ManyToOne(targetEntity: \App\Entity\Wallet\Wallet::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(name: 'id_wallet', referencedColumnName: 'id_wallet')]
    private Wallet $wallet;

    public function getIdTransaction(): int
    {
        return $this->idTransaction;
    }

    public function getMontant(): float
    {
        return $this->montant;
    }

    public function setMontant(float $montant): static
    {
        $this->montant = $montant;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): string|null
    {
        return $this->description;
    }

    public function setDescription(string|null $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getDateTransaction(): \DateTimeInterface
    {
        return $this->dateTransaction;
    }

    public function setDateTransaction(\DateTimeInterface $dateTransaction): static
    {
        $this->dateTransaction = $dateTransaction;
        return $this;
    }

    public function getIdWallet(): int
    {
        return $this->idWallet;
    }

    public function setIdWallet(int $idWallet): static
    {
        $this->idWallet = $idWallet;
        return $this;
    }

    public function getWallet(): Wallet
    {
        return $this->wallet;
    }

    public function setWallet(Wallet $wallet): static
    {
        $this->wallet = $wallet;
        return $this;
    }
}
