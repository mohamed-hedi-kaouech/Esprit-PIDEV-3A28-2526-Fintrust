<?php

namespace App\Entity\User;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entité User — Utilisateur de la plateforme FinTrust.
 *
 * Implémente UserInterface et PasswordAuthenticatedUserInterface
 * pour l'intégration avec le système de sécurité Symfony.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    // ---- Statuts du compte ----
    public const STATUS_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUS_ACTIF      = 'ACTIF';
    public const STATUS_SUSPENDU   = 'SUSPENDU';

    // ---- Statuts KYC ----
    public const KYC_NONE       = null;
    public const KYC_EN_ATTENTE = 'EN_ATTENTE';
    public const KYC_APPROUVE   = 'APPROUVE';
    public const KYC_REFUSE     = 'REFUSE';

    // ---- Rôles ----
    public const ROLE_CLIENT = 'CLIENT';
    public const ROLE_ADMIN  = 'ADMIN';

    public const LANGUAGE_FR = 'fr';
    public const LANGUAGE_EN = 'en';
    public const LANGUAGE_AR = 'ar';

    public const THEME_LIGHT = 'light';
    public const THEME_DARK  = 'dark';

    public const SEGMENT_STANDARD = 'STANDARD';
    public const SEGMENT_VIP      = 'VIP';
    public const SEGMENT_AT_RISK  = 'A_RISQUE';

    public const RISK_LOW      = 'LOW';
    public const RISK_MEDIUM   = 'MEDIUM';
    public const RISK_HIGH     = 'HIGH';
    public const RISK_CRITICAL = 'CRITICAL';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    /** ID du dernier KYC soumis (dénormalisation pour accès rapide) */
    #[ORM\Column(name: 'currentKycId', type: 'integer', nullable: true)]
    private ?int $currentKycId = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 50, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
    private string $nom;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 50)]
    private string $prenom;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    #[Assert\NotBlank(message: "L'adresse e-mail est obligatoire.")]
    #[Assert\Email(message: "L'adresse e-mail « {{ value }} » n'est pas valide.")]
    private string $email;

    #[ORM\Column(name: 'numTel', type: 'string', length: 20, nullable: true)]
    #[Assert\Regex(
        pattern: '/^\+?[0-9\s\-]{8,20}$/',
        message: 'Le numéro de téléphone est invalide.'
    )]
    private ?string $numTel = null;

    /** CLIENT ou ADMIN — stocké en base, converti en ROLE_* pour Symfony */
    #[ORM\Column(type: 'string', length: 10)]
    private string $role = self::ROLE_CLIENT;

    /** Mot de passe hashé (bcrypt/argon2 via Symfony) */
    #[ORM\Column(type: 'string', length: 255)]
    private string $password;

    /** EN_ATTENTE | APPROUVE | REFUSE | null */
    #[ORM\Column(name: 'kycStatus', type: 'string', length: 20, nullable: true)]
    private ?string $kycStatus = null;

    #[ORM\Column(name: 'createdAt', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    /** EN_ATTENTE | ACTIF | SUSPENDU */
    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_EN_ATTENTE;

    #[ORM\Column(name: 'is_verified', type: 'boolean', options: ['default' => true])]
    private bool $isVerified = true;

    #[ORM\Column(name: 'email_verification_code', type: 'string', length: 6, nullable: true)]
    private ?string $emailVerificationCode = null;

    #[ORM\Column(name: 'email_verification_expires_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $emailVerificationExpiresAt = null;

    #[ORM\Column(name: 'email_verified_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $emailVerifiedAt = null;

    #[ORM\Column(name: 'password_changed_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $passwordChangedAt = null;

    #[ORM\Column(name: 'auth_session_version', type: 'integer', options: ['default' => 1])]
    private int $authSessionVersion = 1;

    /** Token unique pour le QR code client */
    #[ORM\Column(name: 'qr_token', type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $qrToken = null;

    #[ORM\Column(name: 'preferred_language', type: 'string', length: 5, options: ['default' => self::LANGUAGE_FR])]
    #[Assert\Choice(choices: [self::LANGUAGE_FR, self::LANGUAGE_EN, self::LANGUAGE_AR], message: 'La langue choisie est invalide.')]
    private string $preferredLanguage = self::LANGUAGE_FR;

    #[ORM\Column(name: 'theme_mode', type: 'string', length: 10, options: ['default' => self::THEME_LIGHT])]
    #[Assert\Choice(choices: [self::THEME_LIGHT, self::THEME_DARK], message: 'Le mode d’affichage choisi est invalide.')]
    private string $themeMode = self::THEME_LIGHT;

    #[ORM\Column(name: 'transaction_frequency', type: 'float', options: ['default' => 0])]
    private float $transactionFrequency = 0.0;

    #[ORM\Column(name: 'average_transaction_amount', type: 'float', options: ['default' => 0])]
    private float $averageTransactionAmount = 0.0;

    #[ORM\Column(name: 'risk_score', type: 'float', options: ['default' => 0])]
    private float $riskScore = 0.0;

    #[ORM\Column(name: 'fraud_score', type: 'float', options: ['default' => 0])]
    private float $fraudScore = 0.0;

    #[ORM\Column(name: 'risk_level', type: 'string', length: 20, options: ['default' => self::RISK_LOW])]
    #[Assert\Choice(choices: [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_CRITICAL], message: 'Le niveau de risque est invalide.')]
    private string $riskLevel = self::RISK_LOW;

    #[ORM\Column(name: 'client_segment', type: 'string', length: 20, options: ['default' => self::SEGMENT_STANDARD])]
    #[Assert\Choice(choices: [self::SEGMENT_STANDARD, self::SEGMENT_VIP, self::SEGMENT_AT_RISK], message: 'Le segment client est invalide.')]
    private string $clientSegment = self::SEGMENT_STANDARD;

    #[ORM\Column(name: 'behavior_updated_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $behaviorUpdatedAt = null;

    // =========================================================================
    // UserInterface
    // =========================================================================

    /** Identifiant unique utilisé par Symfony Security (email). */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Retourne les rôles Symfony à partir du champ `role` en base.
     * ROLE_ADMIN hérite de ROLE_CLIENT et ROLE_USER (voir security.yaml).
     */
    public function getRoles(): array
    {
        return $this->role === self::ROLE_ADMIN
            ? ['ROLE_ADMIN', 'ROLE_USER']
            : ['ROLE_CLIENT', 'ROLE_USER'];
    }

    /** Efface les données sensibles temporaires (non utilisé ici). */
    public function eraseCredentials(): void {}

    // =========================================================================
    // Helpers métier
    // =========================================================================

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

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function isEmailVerificationExpired(): bool
    {
        return $this->emailVerificationExpiresAt === null || $this->emailVerificationExpiresAt < new \DateTimeImmutable();
    }

    public function getFullName(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function isVip(): bool
    {
        return $this->clientSegment === self::SEGMENT_VIP;
    }

    public function isAtRisk(): bool
    {
        return $this->clientSegment === self::SEGMENT_AT_RISK || in_array($this->riskLevel, [self::RISK_HIGH, self::RISK_CRITICAL], true);
    }

    public function isCriticalRisk(): bool
    {
        return $this->riskLevel === self::RISK_CRITICAL;
    }

    public function rotateAuthSessionVersion(): static
    {
        $this->authSessionVersion++;

        return $this;
    }

    public function getEngagementBadge(): string
    {
        if (
            $this->isActive()
            && $this->isKycApproved()
            && $this->isVip()
            && $this->transactionFrequency >= 1.2
            && $this->averageTransactionAmount >= 2500
        ) {
            return 'ROI FINTRUST';
        }

        if (
            $this->isActive()
            && $this->isKycApproved()
            && $this->transactionFrequency >= 0.85
            && $this->averageTransactionAmount >= 1200
        ) {
            return 'ELITE';
        }

        if (
            $this->isActive()
            && $this->transactionFrequency >= 0.45
        ) {
            return 'ACTIF+';
        }

        return 'STANDARD';
    }

    public function getEngagementBadgeTone(): string
    {
        return match ($this->getEngagementBadge()) {
            'ROI FINTRUST' => 'royal',
            'ELITE' => 'elite',
            'ACTIF+' => 'active',
            default => 'standard',
        };
    }

    // =========================================================================
    // Getters / Setters
    // =========================================================================

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

    public function setIsVerified(bool $v): static { $this->isVerified = $v; return $this; }

    public function getEmailVerificationCode(): ?string { return $this->emailVerificationCode; }
    public function setEmailVerificationCode(?string $v): static { $this->emailVerificationCode = $v; return $this; }

    public function getEmailVerificationExpiresAt(): ?\DateTimeInterface { return $this->emailVerificationExpiresAt; }
    public function setEmailVerificationExpiresAt(?\DateTimeInterface $v): static { $this->emailVerificationExpiresAt = $v; return $this; }

    public function getEmailVerifiedAt(): ?\DateTimeInterface { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeInterface $v): static { $this->emailVerifiedAt = $v; return $this; }

    public function getPasswordChangedAt(): ?\DateTimeInterface { return $this->passwordChangedAt; }
    public function setPasswordChangedAt(?\DateTimeInterface $v): static { $this->passwordChangedAt = $v; return $this; }

    public function getAuthSessionVersion(): int { return $this->authSessionVersion; }
    public function setAuthSessionVersion(int $v): static { $this->authSessionVersion = $v; return $this; }

    public function getQrToken(): ?string { return $this->qrToken; }
    public function setQrToken(?string $v): static { $this->qrToken = $v; return $this; }

    public function getPreferredLanguage(): string { return $this->preferredLanguage; }
    public function setPreferredLanguage(string $v): static { $this->preferredLanguage = $v; return $this; }

    public function getThemeMode(): string { return $this->themeMode; }
    public function setThemeMode(string $v): static { $this->themeMode = $v; return $this; }

    public function getTransactionFrequency(): float { return $this->transactionFrequency; }
    public function setTransactionFrequency(float $v): static { $this->transactionFrequency = $v; return $this; }

    public function getAverageTransactionAmount(): float { return $this->averageTransactionAmount; }
    public function setAverageTransactionAmount(float $v): static { $this->averageTransactionAmount = $v; return $this; }

    public function getRiskScore(): float { return $this->riskScore; }
    public function setRiskScore(float $v): static { $this->riskScore = $v; return $this; }

    public function getFraudScore(): float { return $this->fraudScore; }
    public function setFraudScore(float $v): static { $this->fraudScore = $v; return $this; }

    public function getRiskLevel(): string { return $this->riskLevel; }
    public function setRiskLevel(string $v): static { $this->riskLevel = $v; return $this; }

    public function getClientSegment(): string { return $this->clientSegment; }
    public function setClientSegment(string $v): static { $this->clientSegment = $v; return $this; }

    public function getBehaviorUpdatedAt(): ?\DateTimeInterface { return $this->behaviorUpdatedAt; }
    public function setBehaviorUpdatedAt(?\DateTimeInterface $v): static { $this->behaviorUpdatedAt = $v; return $this; }
}
