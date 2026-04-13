<?php

namespace App\Entity\Categorie;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'categorie')]
class Categorie
{
    #[ORM\Id]
    #[ORM\Column(name: 'idCategorie', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idCategorie;

    #[ORM\Column(name: 'nomCategorie', type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'Le nom doit contenir au moins {{ limit }} caracteres',
        maxMessage: 'Le nom ne peut pas depasser {{ limit }} caracteres'
    )]
    #[Assert\Regex(
        pattern: '/^[A-Za-zÀ-ÿ\s]+$/u',
        message: 'Le libelle doit contenir uniquement des lettres'
    )]
    private string $nomCategorie;

    #[ORM\Column(name: 'budgetPrevu', type: 'float')]
    #[Assert\NotBlank(message: 'Le budget est obligatoire')]
    #[Assert\Positive(message: 'Le budget doit etre un nombre positif')]
    private float $budgetPrevu;

    #[ORM\Column(name: 'seuilAlerte', type: 'float')]
    #[Assert\NotBlank(message: 'Le seuil est obligatoire')]
    #[Assert\Positive(message: 'Le seuil doit etre un nombre positif')]
    #[Assert\LessThan(
        propertyPath: 'budgetPrevu',
        message: 'Le seuil doit etre inferieur au budget'
    )]
    private float $seuilAlerte;

    #[ORM\OneToMany(targetEntity: Alerte::class, mappedBy: 'categorie')]
    private Collection $alertes;

    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'categorieRel')]
    private Collection $items;

    public function __construct()
    {
        $this->alertes = new ArrayCollection();
        $this->items = new ArrayCollection();
    }

    public function getIdCategorie(): int
    {
        return $this->idCategorie;
    }

    public function getNomCategorie(): string
    {
        return $this->nomCategorie;
    }

    public function setNomCategorie(string $nomCategorie): static
    {
        $this->nomCategorie = $nomCategorie;

        return $this;
    }

    public function getBudgetPrevu(): float
    {
        return $this->budgetPrevu;
    }

    public function setBudgetPrevu(float $budgetPrevu): static
    {
        $this->budgetPrevu = $budgetPrevu;

        return $this;
    }

    public function getSeuilAlerte(): float
    {
        return $this->seuilAlerte;
    }

    public function setSeuilAlerte(float $seuilAlerte): static
    {
        $this->seuilAlerte = $seuilAlerte;

        return $this;
    }

    public function getAlertes(): Collection
    {
        return $this->alertes;
    }

    public function addAlerte(Alerte $alerte): static
    {
        if (!$this->alertes->contains($alerte)) {
            $this->alertes->add($alerte);
        }

        return $this;
    }

    public function removeAlerte(Alerte $alerte): static
    {
        $this->alertes->removeElement($alerte);

        return $this;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(Item $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        return $this;
    }

    public function removeItem(Item $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }
}
