<?php

namespace App\Entity\Publication;

use App\Entity\User\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'otp_audit')]
class OtpAudit
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'user_id', type: 'integer', nullable: true)]
    private int|null $userId = null;

    #[ORM\Column(name: 'email', type: 'string', length: 190, nullable: true)]
    private string|null $email = null;

    #[ORM\Column(name: 'channel', type: 'string', length: 20)]
    private string $channel;

    #[ORM\Column(name: 'event_type', type: 'string', length: 20)]
    private string $eventType;

    #[ORM\Column(name: 'request_id', type: 'string', length: 64, nullable: true)]
    private string|null $requestId = null;

    #[ORM\Column(name: 'success', type: 'boolean')]
    private bool $success;

    #[ORM\Column(name: 'reason', type: 'string', length: 255, nullable: true)]
    private string|null $reason = null;

    #[ORM\Column(name: 'validation_seconds', type: 'integer', nullable: true)]
    private int|null $validationSeconds = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private User|null $user = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int|null
    {
        return $this->userId;
    }

    public function setUserId(int|null $userId): static
    {
        $this->userId = $userId;
        return $this;
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

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getRequestId(): string|null
    {
        return $this->requestId;
    }

    public function setRequestId(string|null $requestId): static
    {
        $this->requestId = $requestId;
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

    public function getValidationSeconds(): int|null
    {
        return $this->validationSeconds;
    }

    public function setValidationSeconds(int|null $validationSeconds): static
    {
        $this->validationSeconds = $validationSeconds;
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
