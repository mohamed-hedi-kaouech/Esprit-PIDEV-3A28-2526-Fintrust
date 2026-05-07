<?php

namespace App\Entity\User;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'security_events')]
#[ORM\HasLifecycleCallbacks]
class SecurityEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private string|null $ip = null;

    #[ORM\Column(type: 'string', length: 40)]
    private string $type;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private string|null $metadata = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: false)]
    private User $creator;

    public function getId(): int
    {
        return $this->id;
    }

    public function getIp(): string|null
    {
        return $this->ip;
    }

    public function setIp(string|null $ip): static
    {
        $this->ip = $ip;
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

    public function getMetadata(): string|null
    {
        return $this->metadata;
    }

    public function setMetadata(string|null $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    protected function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCreator(): User
    {
        return $this->creator;
    }

    protected function setCreator(User $creator): static
    {
        $this->creator = $creator;
        return $this;
    }

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
