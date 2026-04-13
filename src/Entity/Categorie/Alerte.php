<?php

namespace App\Entity\Categorie;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'alerte')]
class Alerte
{
    #[ORM\Id]
    #[ORM\Column(name: 'idAlerte', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $idAlerte;

    #[ORM\Column(name: 'idCategorie', type: 'integer')]
    private int $idCategorie;

    #[ORM\Column(name: 'message', type: 'string', length: 512)]
    private string $message;

    #[ORM\Column(name: 'seuil', type: 'float')]
    private float $seuil;

    #[ORM\Column(name: 'active', type: 'boolean', nullable: true)]
    private bool|null $active = true;

    #[ORM\Column(name: 'read_status', type: 'boolean', options: ['default' => false])]
    private bool $read = false;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // FIX: added inversedBy: 'alertes' to match Categorie#alertes OneToMany
    #[ORM\ManyToOne(targetEntity: \App\Entity\Categorie\Categorie::class, inversedBy: 'alertes')]
    #[ORM\JoinColumn(name: 'idCategorie', referencedColumnName: 'idCategorie', onDelete: 'CASCADE')]
    private Categorie $categorie;

    public function getIdAlerte(): int
    {
        return $this->idAlerte;
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

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getSeuil(): float
    {
        return $this->seuil;
    }

    public function setSeuil(float $seuil): static
    {
        $this->seuil = $seuil;
        return $this;
    }

    public function getActive(): bool|null
    {
        return $this->active;
    }

    public function setActive(bool|null $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function isRead(): bool
    {
        return $this->read;
    }

    public function setRead(bool $read): static
    {
        $this->read = $read;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
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
}
