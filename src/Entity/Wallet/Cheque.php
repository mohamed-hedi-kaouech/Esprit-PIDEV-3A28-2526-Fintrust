<?php

namespace App\Entity\Wallet;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cheque')]
class Cheque
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_cheque', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idCheque;

    #[ORM\Column(name: 'numero_cheque', type: 'string', length: 20)]
    private string $numeroCheque;

    #[ORM\Column(name: 'montant', type: 'float')]
    private float $montant;

    #[ORM\Column(name: 'date_emission', type: 'datetime')]
    private \DateTimeInterface $dateEmission;

    #[ORM\Column(name: 'date_presentation', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $datePresentation = null;

    #[ORM\Column(name: 'statut', type: 'string', length: 20)]
    private string $statut;

    #[ORM\Column(name: 'id_wallet', type: 'integer')]
    private int $idWallet;

    #[ORM\Column(name: 'beneficiaire', type: 'string', length: 100, nullable: true)]
    private string|null $beneficiaire = null;

    #[ORM\Column(name: 'motif_rejet', type: 'string', length: 255, nullable: true)]
    private string|null $motifRejet = null;

    // FIX: added ManyToOne relation with inversedBy: 'cheques' to match Wallet#cheques OneToMany
    #[ORM\ManyToOne(targetEntity: \App\Entity\Wallet\Wallet::class, inversedBy: 'cheques')]
    #[ORM\JoinColumn(name: 'id_wallet', referencedColumnName: 'id_wallet')]
    private Wallet $wallet;

    public function getIdCheque(): int
    {
        return $this->idCheque;
    }

    public function getNumeroCheque(): string
    {
        return $this->numeroCheque;
    }

    public function setNumeroCheque(string $numeroCheque): static
    {
        $this->numeroCheque = $numeroCheque;
        return $this;
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

    public function getDateEmission(): \DateTimeInterface
    {
        return $this->dateEmission;
    }

    public function setDateEmission(\DateTimeInterface $dateEmission): static
    {
        $this->dateEmission = $dateEmission;
        return $this;
    }

    public function getDatePresentation(): \DateTimeInterface|null
    {
        return $this->datePresentation;
    }

    public function setDatePresentation(\DateTimeInterface|null $datePresentation): static
    {
        $this->datePresentation = $datePresentation;
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

    public function getIdWallet(): int
    {
        return $this->idWallet;
    }

    public function setIdWallet(int $idWallet): static
    {
        $this->idWallet = $idWallet;
        return $this;
    }

    public function getBeneficiaire(): string|null
    {
        return $this->beneficiaire;
    }

    public function setBeneficiaire(string|null $beneficiaire): static
    {
        $this->beneficiaire = $beneficiaire;
        return $this;
    }

    public function getMotifRejet(): string|null
    {
        return $this->motifRejet;
    }

    public function setMotifRejet(string|null $motifRejet): static
    {
        $this->motifRejet = $motifRejet;
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
