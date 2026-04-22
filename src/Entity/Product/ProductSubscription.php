<?php

namespace App\Entity\Product;

use App\Entity\User\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'productsubscription')]
class ProductSubscription
{
    #[ORM\Id]
    #[ORM\Column(name: 'subscriptionId', type: 'integer')]
    #[ORM\GeneratedValue]
    private int $subscriptionId;

    #[ORM\Column(name: 'client', type: 'integer')]
    private int $client;

    #[ORM\Column(name: 'product', type: 'integer')]
    private int $product;

    #[ORM\Column(name: 'type', type: 'string')]
    private string $type;

    #[ORM\Column(name: 'subscriptionDate', type: 'date')]
    private \DateTimeInterface $subscriptionDate;

    #[ORM\Column(name: 'expirationDate', type: 'date')]
    private \DateTimeInterface $expirationDate;

    #[ORM\Column(name: 'status', type: 'string')]
    private string $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'client', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private User $clientUser;

    // FIX: added inversedBy: 'subscriptions' to match Product#subscriptions OneToMany
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(name: 'product', referencedColumnName: 'productId', onDelete: 'CASCADE')]
    private Product $productObj;

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function getClient(): int
    {
        return $this->client;
    }

    public function setClient(int $client): static
    {
        $this->client = $client;
        return $this;
    }

    public function getProduct(): int
    {
        return $this->product;
    }

    public function setProduct(int $product): static
    {
        $this->product = $product;
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

    public function getSubscriptionDate(): \DateTimeInterface
    {
        return $this->subscriptionDate;
    }

    public function setSubscriptionDate(\DateTimeInterface $subscriptionDate): static
    {
        $this->subscriptionDate = $subscriptionDate;
        return $this;
    }

    public function getExpirationDate(): \DateTimeInterface
    {
        return $this->expirationDate;
    }

    public function setExpirationDate(\DateTimeInterface $expirationDate): static
    {
        $this->expirationDate = $expirationDate;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getClientUser(): User
    {
        return $this->clientUser;
    }

    public function setClientUser(User $clientUser): static
    {
        $this->clientUser = $clientUser;
        return $this;
    }

    public function getProductObj(): Product
    {
        return $this->productObj;
    }

    public function setProductObj(Product $productObj): static
    {
        $this->productObj = $productObj;
        return $this;
    }
}