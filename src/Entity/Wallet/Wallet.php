<?php

namespace App\Entity\Wallet;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'wallet')]
class Wallet
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_wallet', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idWallet;

    #[ORM\Column(name: 'id_user', type: 'integer', nullable: true)]
    private int|null $idUser = null;

    #[ORM\Column(name: 'nom_proprietaire', type: 'string', length: 100)]
    private string $nomProprietaire;

    #[ORM\Column(name: 'telephone', type: 'string', length: 20, nullable: true)]
    private string|null $telephone = null;

    #[ORM\Column(name: 'email', type: 'string', length: 100, nullable: true)]
    private string|null $email = null;

    #[ORM\Column(name: 'code_acces', type: 'string', length: 10, nullable: true)]
    private string|null $codeAcces = null;

    #[ORM\Column(name: 'est_actif', type: 'boolean', nullable: true)]
    private bool|null $estActif = false;

    // FIX: changed type from 'float' to 'decimal' with precision/scale to fix InvalidColumnDeclaration
    #[ORM\Column(name: 'solde', type: 'decimal', precision: 15, scale: 2)]
    private string $solde;

    #[ORM\Column(name: 'plafond_decouvert', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private string|null $plafondDecouvert = null;

    #[ORM\Column(name: 'devise', type: 'string')]
    private string $devise;

    #[ORM\Column(name: 'statut', type: 'string')]
    private string $statut;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    private \DateTimeInterface $dateCreation;

    #[ORM\Column(name: 'tentatives_echouees', type: 'integer', nullable: true)]
    private int|null $tentativesEchouees = 0;

    #[ORM\Column(name: 'date_derniere_tentative', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $dateDerniereTentative = null;

    #[ORM\Column(name: 'est_bloque', type: 'boolean', nullable: true)]
    private bool|null $estBloque = false;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'id_user', referencedColumnName: 'id', nullable: true)]
    private User|null $user = null;

    // FIX: added OneToMany for transactions (was referenced in error but missing from entity)
    #[ORM\OneToMany(targetEntity: \App\Entity\Wallet\Transaction::class, mappedBy: 'wallet')]
    private Collection $transactions;

    // FIX: added OneToMany for cheques (was referenced in error but missing from entity)
    #[ORM\OneToMany(targetEntity: \App\Entity\Wallet\Cheque::class, mappedBy: 'wallet')]
    private Collection $cheques;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->cheques = new ArrayCollection();
    }

    public function getIdWallet(): int
    {
        return $this->idWallet;
    }

    public function getIdUser(): int|null
    {
        return $this->idUser;
    }

    public function setIdUser(int|null $idUser): static
    {
        $this->idUser = $idUser;
        return $this;
    }

    public function getNomProprietaire(): string
    {
        return $this->nomProprietaire;
    }

    public function setNomProprietaire(string $nomProprietaire): static
    {
        $this->nomProprietaire = $nomProprietaire;
        return $this;
    }

    public function getTelephone(): string|null
    {
        return $this->telephone;
    }

    public function setTelephone(string|null $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getEmail(): string|null
    {
        return $this->email;
    }

    public function setEmail(string|null $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getCodeAcces(): string|null
    {
        return $this->codeAcces;
    }

    public function setCodeAcces(string|null $codeAcces): static
    {
        $this->codeAcces = $codeAcces;
        return $this;
    }

    public function getEstActif(): bool|null
    {
        return $this->estActif;
    }

    public function setEstActif(bool|null $estActif): static
    {
        $this->estActif = $estActif;
        return $this;
    }

    public function getSolde(): string
    {
        return $this->solde;
    }

    public function setSolde(string $solde): static
    {
        $this->solde = $solde;
        return $this;
    }

    public function getPlafondDecouvert(): string|null
    {
        return $this->plafondDecouvert;
    }

    public function setPlafondDecouvert(string|null $plafondDecouvert): static
    {
        $this->plafondDecouvert = $plafondDecouvert;
        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;
        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getDateCreation(): \DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeInterface $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getTentativesEchouees(): int|null
    {
        return $this->tentativesEchouees;
    }

    public function setTentativesEchouees(int|null $tentativesEchouees): static
    {
        $this->tentativesEchouees = $tentativesEchouees;
        return $this;
    }

    public function getDateDerniereTentative(): \DateTimeInterface|null
    {
        return $this->dateDerniereTentative;
    }

    public function setDateDerniereTentative(\DateTimeInterface|null $dateDerniereTentative): static
    {
        $this->dateDerniereTentative = $dateDerniereTentative;
        return $this;
    }

    public function getEstBloque(): bool|null
    {
        return $this->estBloque;
    }

    public function setEstBloque(bool|null $estBloque): static
    {
        $this->estBloque = $estBloque;
        return $this;
    }

    public function getUser(): User|null
    {
        return $this->user;
    }

    public function setUser(User|null $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }
        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        $this->transactions->removeElement($transaction);
        return $this;
    }

    public function getCheques(): Collection
    {
        return $this->cheques;
    }

    public function addCheque(Cheque $cheque): static
    {
        if (!$this->cheques->contains($cheque)) {
            $this->cheques->add($cheque);
        }
        return $this;
    }

    public function removeCheque(Cheque $cheque): static
    {
        $this->cheques->removeElement($cheque);
        return $this;
    }
}
