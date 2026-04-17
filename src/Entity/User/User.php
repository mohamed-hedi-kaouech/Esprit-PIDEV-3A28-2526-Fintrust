<?php

namespace App\Entity\User;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entite User.
 *
 * Cette version ne mappe que les colonnes reellement presentes dans la table
 * `users`. Les anciennes proprietes non persistables sont conservees, quand
 * utile, sous forme de compatibilite applicative non Doctrine.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe deja avec cette adresse e-mail.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const STATUS_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUS_ACTIF = 'ACTIF';
    public const STATUS_SUSPENDU = 'SUSPENDU';

    public const KYC_NONE = null;
    public const KYC_EN_ATTENTE = 'EN_ATTENTE';
    public const KYC_APPROUVE = 'APPROUVE';
    public const KYC_REFUSE = 'REFUSE';

    public const ROLE_CLIENT = 'CLIENT';
    public const ROLE_ADMIN = 'ADMIN';

    public const LANGUAGE_FR = 'fr';
    public const LANGUAGE_EN = 'en';
    public const LANGUAGE_AR = 'ar';

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';

    public const SEGMENT_STANDARD = 'STANDARD';
    public const SEGMENT_VIP = 'VIP';
    public const SEGMENT_AT_RISK = 'A_RISQUE';

    public const RISK_LOW = 'LOW';
    public const RISK_MEDIUM = 'MEDIUM';
    public const RISK_HIGH = 'HIGH';
    public const RISK_CRITICAL = 'CRITICAL';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'currentKycId', type: 'integer', nullable: true)]
    private ?int $currentKycId = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 50, maxMessage: 'Le nom ne peut pas depasser {{ limit }} caracteres.')]
    private string $nom;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Le prenom est obligatoire.')]
    #[Assert\Length(max: 50)]
    private string $prenom;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    #[Assert\NotBlank(message: "L'adresse e-mail est obligatoire.")]
    #[Assert\Email(message: "L'adresse e-mail « {{ value }} » n'est pas valide.")]
    private string $email;

    #[ORM\Column(name: 'numTel', type: 'string', length: 20, nullable: true)]
    #[Assert\Regex(
        pattern: '/^\+?[0-9\s\-]{8,20}$/',
        message: 'Le numero de telephone est invalide.'
    )]
    private ?string $numTel = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $role = self::ROLE_CLIENT;

    #[ORM\Column(type: 'string', length: 255)]
    private string $password;

    #[ORM\Column(name: 'kycStatus', type: 'string', length: 20, nullable: true)]
    private ?string $kycStatus = null;

    #[ORM\Column(name: 'createdAt', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_EN_ATTENTE;

    /**
     * Proprietes transitoires de compatibilite, non mappees en base.
     * Elles evitent de casser brutalement certains services existants.
     */
    private ?string $emailVerificationCode = null;
    private ?\DateTimeInterface $emailVerificationExpiresAt = null;
    private ?\DateTimeInterface $emailVerifiedAt = null;

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->role === self::ROLE_ADMIN
            ? ['ROLE_ADMIN', 'ROLE_USER']
            : ['ROLE_CLIENT', 'ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function isKycApproved(): bool
    {
        return $this->kycStatus === self::KYC_APPROUVE;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIF;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Compatibilite sans colonne `is_verified`.
     * On considere qu'un compte non suspendu est autorise a se connecter.
     */
    public function isVerified(): bool
    {
        return $this->status !== self::STATUS_SUSPENDU;
    }

    public function setIsVerified(bool $verified): static
    {
        if ($verified && $this->status === self::STATUS_EN_ATTENTE) {
            $this->status = self::STATUS_ACTIF;
        }

        if (!$verified && $this->status === self::STATUS_ACTIF) {
            $this->status = self::STATUS_EN_ATTENTE;
        }

        return $this;
    }

    public function isEmailVerificationExpired(): bool
    {
        return $this->emailVerificationExpiresAt !== null
            && $this->emailVerificationExpiresAt < new \DateTimeImmutable();
    }

    public function getFullName(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function isVip(): bool
    {
        return false;
    }

    public function isAtRisk(): bool
    {
        return $this->status === self::STATUS_SUSPENDU;
    }

    public function isCriticalRisk(): bool
    {
        return false;
    }

    public function rotateAuthSessionVersion(): static
    {
        return $this;
    }

    public function getEngagementBadge(): string
    {
        if ($this->isActive() && $this->isKycApproved()) {
            return 'ELITE';
        }

        if ($this->isActive()) {
            return 'ACTIF+';
        }

        return 'STANDARD';
    }

    public function getEngagementBadgeTone(): string
    {
        return match ($this->getEngagementBadge()) {
            'ELITE' => 'elite',
            'ACTIF+' => 'active',
            default => 'standard',
        };
    }

    public function getId(): int { return $this->id; }

    public function getCurrentKycId(): ?int { return $this->currentKycId; }
    public function setCurrentKycId(?int $v): static { $this->currentKycId = $v; return $this; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $v): static { $this->nom = $v; return $this; }

    public function getPrenom(): string { return $this->prenom; }
    public function setPrenom(string $v): static { $this->prenom = $v; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): static { $this->email = $v; return $this; }

    public function getNumTel(): ?string { return $this->numTel; }
    public function setNumTel(?string $v): static { $this->numTel = $v; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $v): static { $this->role = $v; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $v): static { $this->password = $v; return $this; }

    public function getKycStatus(): ?string { return $this->kycStatus; }
    public function setKycStatus(?string $v): static { $this->kycStatus = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getEmailVerificationCode(): ?string { return $this->emailVerificationCode; }
    public function setEmailVerificationCode(?string $v): static { $this->emailVerificationCode = $v; return $this; }

    public function getEmailVerificationExpiresAt(): ?\DateTimeInterface { return $this->emailVerificationExpiresAt; }
    public function setEmailVerificationExpiresAt(?\DateTimeInterface $v): static { $this->emailVerificationExpiresAt = $v; return $this; }

    public function getEmailVerifiedAt(): ?\DateTimeInterface { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeInterface $v): static { $this->emailVerifiedAt = $v; return $this; }

    public function getAuthSessionVersion(): int { return 1; }
    public function setAuthSessionVersion(int $v): static { return $this; }

    public function getQrToken(): ?string { return null; }
    public function setQrToken(?string $v): static { return $this; }

    public function getPreferredLanguage(): string { return self::LANGUAGE_FR; }
    public function setPreferredLanguage(string $v): static { return $this; }

    public function getThemeMode(): string { return self::THEME_LIGHT; }
    public function setThemeMode(string $v): static { return $this; }

    public function getTransactionFrequency(): float { return 0.0; }
    public function setTransactionFrequency(float $v): static { return $this; }

    public function getAverageTransactionAmount(): float { return 0.0; }
    public function setAverageTransactionAmount(float $v): static { return $this; }

    public function getRiskScore(): float { return $this->isAtRisk() ? 75.0 : 10.0; }
    public function setRiskScore(float $v): static { return $this; }

    public function getFraudScore(): float { return $this->isAtRisk() ? 60.0 : 5.0; }
    public function setFraudScore(float $v): static { return $this; }

    public function getRiskLevel(): string
    {
        return $this->isAtRisk() ? self::RISK_HIGH : self::RISK_LOW;
    }

    public function setRiskLevel(string $v): static { return $this; }

    public function getClientSegment(): string
    {
        return $this->isAtRisk() ? self::SEGMENT_AT_RISK : self::SEGMENT_STANDARD;
    }

    public function setClientSegment(string $v): static { return $this; }

    public function getBehaviorUpdatedAt(): ?\DateTimeInterface { return null; }
    public function setBehaviorUpdatedAt(?\DateTimeInterface $v): static { return $this; }
}
