<?php

namespace App\Entity\Categorie;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    #[Assert\NotBlank(message: 'Le nom de la catégorie ne peut pas être vide')]
    #[Assert\Length(min: 3, max: 255, minMessage: 'Le nom doit contenir au moins 3 caractères', maxMessage: 'Le nom ne peut pas dépasser 255 caractères')]
    private string $nomCategorie;

    #[ORM\Column(name: 'budgetPrevu', type: 'float')]
    #[Assert\NotBlank(message: 'Le budget prévu ne peut pas être vide')]
    #[Assert\Positive(message: 'Le budget prévu doit être un nombre positif')]
    private float $budgetPrevu;

    #[ORM\Column(name: 'seuilAlerte', type: 'float')]
    #[Assert\NotBlank(message: 'Le seuil d\'alerte ne peut pas être vide')]
    #[Assert\Positive(message: 'Le seuil d\'alerte doit être un nombre positif')]
    private float $seuilAlerte;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private ?\App\Entity\User\User $user = null;

    #[ORM\OneToMany(targetEntity: \App\Entity\Categorie\Alerte::class, mappedBy: 'categorie')]
    private Collection $alertes;

    #[ORM\OneToMany(targetEntity: \App\Entity\Categorie\Item::class, mappedBy: 'categorie', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getUser(): ?\App\Entity\User\User
    {
        return $this->user;
    }

    public function setUser(?\App\Entity\User\User $user): static
    {
        $this->user = $user;
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
            $item->setCategorie($this);
        }
        return $this;
    }

    public function removeItem(Item $item): static
    {
        $this->items->removeElement($item);
        return $this;
    }
}
