<?php

namespace App\Entity\User;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_login_audit')]
class UserLoginAudit
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private string|null $email = null;

    #[ORM\Column(type: 'boolean')]
    private bool $success;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private string|null $reason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private User|null $user = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string|null
    {
        return $this->email;
    }

    public function setEmail(string|null $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;
        return $this;
    }

    public function getReason(): string|null
    {
        return $this->reason;
    }

    public function setReason(string|null $reason): static
    {
        $this->reason = $reason;
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

    public function getUser(): User|null
    {
        return $this->user;
    }

    public function setUser(User|null $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function isSuccess(): ?bool
    {
        return $this->success;
    }
}
