<?php

namespace App\Entity\User\Client;

use App\Entity\User\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entité Notification — Notifications internes par utilisateur.
 *
 * Stocke les messages envoyés aux clients (KYC approuvé/refusé, alertes admin, etc.).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    /** Utilisateur destinataire */
    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    private User $user;

    /** Contenu du message */
    #[ORM\Column(type: 'text')]
    private string $message;

    /** Type : INFO | SUCCESS | WARNING | ERROR */
    #[ORM\Column(type: 'string', length: 20)]
    private string $type = 'INFO';

    /** Indique si la notification a été lue */
    #[ORM\Column(name: 'is_read', type: 'boolean')]
    private bool $isRead = false;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // ---- Getters / Setters ----

    public function getId(): int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $v): static { $this->user = $v; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $v): static { $this->message = $v; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }

    public function isRead(): bool { return $this->isRead; }
    public function setIsRead(bool $v): static { $this->isRead = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }
}
