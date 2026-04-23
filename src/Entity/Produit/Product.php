<?php

namespace App\Entity\Produit;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(name: 'productId', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $productId;

    #[ORM\Column(name: 'category', type: 'string')]
    private string $category = 'COMPTE_COURANT';

    #[ORM\Column(name: 'price', type: 'float')]
    private float $price;

    #[ORM\Column(name: 'description', type: 'string', length: 500)]
    private string $description;

    #[ORM\Column(name: 'createdAt', type: 'date')]
    private \DateTimeInterface $createdAt;

    #[ORM\OneToMany(targetEntity: \App\Entity\Produit\ProductSubscription::class, mappedBy: 'productObj')]
    private Collection $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new ArrayCollection();
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function addSubscription(ProductSubscription $subscription): static
    {
        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
        }
        return $this;
    }

    public function removeSubscription(ProductSubscription $subscription): static
    {
        $this->subscriptions->removeElement($subscription);
        return $this;
    }
}
