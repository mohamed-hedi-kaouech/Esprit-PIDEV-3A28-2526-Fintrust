<?php

namespace App\Entity\User\Client;

use App\Entity\User\User;
use App\Repository\PasswordResetRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
#[ORM\Table(name: 'password_reset_request')]
#[ORM\Index(columns: ['public_id'], name: 'idx_password_reset_public_id')]
#[ORM\Index(columns: ['channel', 'status'], name: 'idx_password_reset_channel_status')]
#[ORM\Index(columns: ['recovery_hash'], name: 'idx_password_reset_recovery_hash')]
class PasswordResetRequest
{
    public const CHANNEL_EMAIL = 'EMAIL';
    public const CHANNEL_SMS = 'SMS';
    public const CHANNEL_WHATSAPP = 'WHATSAPP';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_USED = 'USED';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', type: 'string', length: 64, unique: true)]
    private string $publicId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'recovery_hash', type: 'string', length: 64)]
    private string $recoveryHash;

    #[ORM\Column(type: 'string', length: 20)]
    private string $channel;

    #[ORM\Column(name: 'secret_hash', type: 'string', length: 255, nullable: true)]
    private ?string $secretHash = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(name: 'verified_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    #[ORM\Column(name: 'used_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $usedAt = null;

    #[ORM\Column(name: 'attempts_count', type: 'integer', options: ['default' => 0])]
    private int $attemptsCount = 0;

    #[ORM\Column(name: 'resend_count', type: 'integer', options: ['default' => 0])]
    private int $resendCount = 0;

    #[ORM\Column(name: 'last_sent_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastSentAt = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'request_ip', type: 'string', length: 45, nullable: true)]
    private ?string $requestIp = null;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $now = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function setPublicId(string $publicId): static
    {
        $this->publicId = $publicId;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRecoveryHash(): string
    {
        return $this->recoveryHash;
    }

    public function setRecoveryHash(string $recoveryHash): static
    {
        $this->recoveryHash = $recoveryHash;

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

    public function getSecretHash(): ?string
    {
        return $this->secretHash;
    }

    public function setSecretHash(?string $secretHash): static
    {
        $this->secretHash = $secretHash;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeInterface
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeInterface $usedAt): static
    {
        $this->usedAt = $usedAt;

        return $this;
    }

    public function getAttemptsCount(): int
    {
        return $this->attemptsCount;
    }

    public function setAttemptsCount(int $attemptsCount): static
    {
        $this->attemptsCount = $attemptsCount;

        return $this;
    }

    public function incrementAttemptsCount(): static
    {
        $this->attemptsCount++;

        return $this;
    }

    public function getResendCount(): int
    {
        return $this->resendCount;
    }

    public function setResendCount(int $resendCount): static
    {
        $this->resendCount = $resendCount;

        return $this;
    }

    public function incrementResendCount(): static
    {
        $this->resendCount++;

        return $this;
    }

    public function getLastSentAt(): ?\DateTimeInterface
    {
        return $this->lastSentAt;
    }

    public function setLastSentAt(?\DateTimeInterface $lastSentAt): static
    {
        $this->lastSentAt = $lastSentAt;

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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt === null || $this->expiresAt < new \DateTime();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED && $this->verifiedAt !== null;
    }

    public function markExpired(): static
    {
        $this->status = self::STATUS_EXPIRED;
        $this->touch();

        return $this;
    }

    public function markVerified(): static
    {
        $this->status = self::STATUS_VERIFIED;
        $this->verifiedAt = new \DateTime();
        $this->touch();

        return $this;
    }

    public function markUsed(): static
    {
        $this->status = self::STATUS_USED;
        $this->usedAt = new \DateTime();
        $this->touch();

        return $this;
    }

    public function cancel(): static
    {
        $this->status = self::STATUS_CANCELLED;
        $this->touch();

        return $this;
    }
}
