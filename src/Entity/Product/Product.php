<?php

namespace App\Entity\Product;

use Doctrine\DBAL\Types\Types;
use App\Repository\Product\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    public const CATEGORIES = [
        'COMPTE_COURANT',
        'COMPTE_EPARGNE',
        'COMPTE_PREMIUM',
        'COMPTE_JEUNE',
        'COMPTE_ENTREPRISE',
        'CARTE_DEBIT',
        'CARTE_CREDIT',
        'CARTE_PREMIUM',
        'CARTE_VIRTUELLE',
        'EPARGNE_CLASSIQUE',
        'EPARGNE_LOGEMENT',
        'DEPOT_A_TERME',
        'PLACEMENT_INVESTISSEMENT',
        'ASSURANCE_VIE',
        'ASSURANCE_HABITATION',
        'ASSURANCE_VOYAGE',
    ];

    #[ORM\Id]
    #[ORM\Column(name: 'productId', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $productId;

    #[ORM\Column(name: 'category', type: 'string')]
    #[Assert\NotBlank(message: 'La categorie est obligatoire.')]
    #[Assert\Choice(choices: self::CATEGORIES, message: 'La categorie selectionnee est invalide.')]
    private string $category = 'COMPTE_COURANT';

    #[ORM\Column(name: 'price', type: 'float')]
    #[Assert\NotNull(message: 'Le prix est obligatoire.')]
    #[Assert\Positive(message: 'Le prix doit etre un nombre strictement positif.')]
    private float $price = 0.0;

    #[ORM\Column(name: 'description', type: 'string', length: 500)]
    #[Assert\NotBlank(message: 'La description est obligatoire.')]
    #[Assert\Length(
        min: 4,
        max: 500,
        minMessage: 'La description doit contenir au moins {{ limit }} caracteres.',
        maxMessage: 'La description ne doit pas depasser {{ limit }} caracteres.'
    )]
    private string $description = '';

    #[ORM\Column(name: 'createdAt', type: 'date')]
    private \DateTimeInterface $createdAt;

    #[ORM\OneToMany(targetEntity: ProductSubscription::class, mappedBy: 'productObj')]
    private Collection $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new ArrayCollection();
    }

    public static function getAllowedCategories(): array
    {
        return self::CATEGORIES;
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
