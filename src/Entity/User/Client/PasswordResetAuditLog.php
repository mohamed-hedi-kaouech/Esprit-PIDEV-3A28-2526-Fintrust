<?php

namespace App\Entity\User\Client;

use App\Repository\PasswordResetAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetAuditLogRepository::class)]
#[ORM\Table(name: 'password_reset_audit_log')]
#[ORM\Index(columns: ['event_type', 'created_at'], name: 'idx_password_reset_audit_event')]
#[ORM\Index(columns: ['recovery_hash', 'created_at'], name: 'idx_password_reset_audit_recovery')]
class PasswordResetAuditLog
{
    public const EVENT_REQUEST_RECEIVED = 'REQUEST_RECEIVED';
    public const EVENT_CHANNEL_DISPATCHED = 'CHANNEL_DISPATCHED';
    public const EVENT_RATE_LIMITED = 'RATE_LIMITED';
    public const EVENT_OTP_FAILED = 'OTP_FAILED';
    public const EVENT_OTP_VERIFIED = 'OTP_VERIFIED';
    public const EVENT_LINK_VERIFIED = 'LINK_VERIFIED';
    public const EVENT_TOKEN_INVALID = 'TOKEN_INVALID';
    public const EVENT_EXPIRED = 'EXPIRED';
    public const EVENT_PASSWORD_CHANGED = 'PASSWORD_CHANGED';
    public const EVENT_RESENT = 'RESENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PasswordResetRequest::class)]
    #[ORM\JoinColumn(name: 'password_reset_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PasswordResetRequest $passwordResetRequest = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 50)]
    private string $eventType;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $channel = null;

    #[ORM\Column(name: 'recovery_hash', type: 'string', length: 64, nullable: true)]
    private ?string $recoveryHash = null;

    #[ORM\Column(name: 'request_ip', type: 'string', length: 45, nullable: true)]
    private ?string $requestIp = null;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $context = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasswordResetRequest(): ?PasswordResetRequest
    {
        return $this->passwordResetRequest;
    }

    public function setPasswordResetRequest(?PasswordResetRequest $passwordResetRequest): static
    {
        $this->passwordResetRequest = $passwordResetRequest;

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

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(?string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getRecoveryHash(): ?string
    {
        return $this->recoveryHash;
    }

    public function setRecoveryHash(?string $recoveryHash): static
    {
        $this->recoveryHash = $recoveryHash;

        return $this;
    }

    public function getRequestIp(): ?string
    {
        return $this->requestIp;
    }

    public function setRequestIp(?string $requestIp): static
    {
        $this->requestIp = $requestIp;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;

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
}
