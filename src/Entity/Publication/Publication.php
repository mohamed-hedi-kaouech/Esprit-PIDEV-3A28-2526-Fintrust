<?php

namespace App\Entity\Publication;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'publication')]
class Publication
{
    #[ORM\Id]
    #[ORM\Column(name: 'id_publication', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idPublication;

    #[ORM\Column(name: 'titre', type: 'string', length: 255)]
    private string $titre;

    #[ORM\Column(name: 'contenu', type: 'text')]
    private string $contenu;

    #[ORM\Column(name: 'categorie', type: 'string', length: 100, nullable: true)]
    private string|null $categorie = null;

    #[ORM\Column(name: 'statut', type: 'string', length: 50, nullable: true)]
    private string|null $statut = null;

    #[ORM\Column(name: 'est_visible', type: 'boolean', nullable: true)]
    private bool|null $estVisible = true;

    #[ORM\Column(name: 'date_publication', type: 'datetime', nullable: true)]
    private \DateTimeInterface|null $datePublication = null;

    #[ORM\OneToMany(targetEntity: \App\Entity\User\Feedback::class, mappedBy: 'publication')]
    private Collection $feedbacks;

    public function __construct()
    {
        $this->feedbacks = new ArrayCollection();
    }

    public function getIdPublication(): int
    {
        return $this->idPublication;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getCategorie(): string|null
    {
        return $this->categorie;
    }

    public function setCategorie(string|null $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getStatut(): string|null
    {
        return $this->statut;
    }

    public function setStatut(string|null $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getEstVisible(): bool|null
    {
        return $this->estVisible;
    }

    public function setEstVisible(bool|null $estVisible): static
    {
        $this->estVisible = $estVisible;
        return $this;
    }

    public function getDatePublication(): \DateTimeInterface|null
    {
        return $this->datePublication;
    }

    public function setDatePublication(\DateTimeInterface|null $datePublication): static
    {
        $this->datePublication = $datePublication;
        return $this;
    }

    public function getFeedbacks(): Collection
    {
        return $this->feedbacks;
    }

    public function addFeedback(Feedback $feedback): static
    {
        if (!$this->feedbacks->contains($feedback)) {
            $this->feedbacks->add($feedback);
        }
        return $this;
    }

    public function removeFeedback(Feedback $feedback): static
    {
        $this->feedbacks->removeElement($feedback);
        return $this;
    }

    public function isEstVisible(): ?bool
    {
        return $this->estVisible;
    }
}
