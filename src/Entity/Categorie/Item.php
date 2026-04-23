<?php

namespace App\Entity\Categorie;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'item')]
class Item
{
    #[ORM\Id]
    #[ORM\Column(name: 'idItem', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idItem;

    #[ORM\Column(name: 'libelle', type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le libellé ne peut pas être vide')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Le libellé doit contenir au moins 3 caractères', maxMessage: 'Le libellé ne peut pas dépasser 255 caractères')]
    private string $libelle;

    #[ORM\Column(name: 'montant', type: 'float')]
    #[Assert\NotBlank(message: 'Le montant ne peut pas être vide')]
    #[Assert\Positive(message: 'Le montant doit être un nombre positif')]
    private float $montant;

    // FIX: renamed column to avoid conflict with the association property below
    #[ORM\Column(name: 'categorie', type: 'string', length: 255, nullable: true)]
    private string|null $categorieLabel = null;

    #[ORM\Column(name: 'idCategorie', type: 'integer')]
    private int $idCategorie;

    // FIX: added inversedBy: 'items' to match Categorie#items OneToMany
    #[ORM\ManyToOne(targetEntity: Categorie::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'idCategorie', referencedColumnName: 'idCategorie')]
    private Categorie $categorie;

    private \DateTimeInterface|null $dateCreation = null;

    #[ORM\Column(name: 'quantite', type: 'float', nullable: true, options: ['default' => 1])]
    #[Assert\Positive(message: 'La quantité doit être un nombre positif')]
    private float|null $quantite = 1;

    #[ORM\Column(name: 'tva', type: 'float', nullable: true, options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'La TVA ne peut pas être négative')]
    #[Assert\LessThanOrEqual(value: 100, message: 'La TVA ne peut pas dépasser 100%')]
    private float|null $tva = 0;

    #[ORM\Column(name: 'prix_unitaire', type: 'float', nullable: true)]
    #[Assert\Positive(message: 'Le prix unitaire doit être positif')]
    private float|null $prixUnitaire = null;

    public function getIdItem(): int
    {
        return $this->idItem;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
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

    public function getCategorieLabel(): string|null
    {
        return $this->categorieLabel;
    }

    public function setCategorieLabel(string|null $categorieLabel): static
    {
        $this->categorieLabel = $categorieLabel;
        return $this;
    }

    public function getIdCategorie(): int
    {
        return $this->idCategorie;
    }

    public function setIdCategorie(int $idCategorie): static
    {
        $this->idCategorie = $idCategorie;
        return $this;
    }

    public function getCategorie(): Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(Categorie $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getDateCreation(): \DateTimeInterface|null
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeInterface|null $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getName(): string
    {
        return $this->libelle;
    }

    public function getQuantite(): float|null { return $this->quantite; }
    public function setQuantite(float|null $quantite): static { $this->quantite = $quantite; return $this; }

    public function getTva(): float|null { return $this->tva; }
    public function setTva(float|null $tva): static { $this->tva = $tva; return $this; }

    public function getPrixUnitaire(): float|null { return $this->prixUnitaire; }
    public function setPrixUnitaire(float|null $v): static { $this->prixUnitaire = $v; return $this; }

    /** Montant TTC calculé = prixUnitaire * quantite * (1 + tva/100) */
    public function getMontantTtc(): float
    {
        $q   = $this->quantite ?? 1;
        $tva = $this->tva ?? 0;
        return round($this->montant * $q * (1 + $tva / 100), 3);
    }
}
