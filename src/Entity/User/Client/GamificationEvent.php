<?php

namespace App\Entity\User\Client;

use App\Entity\User\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'gamification_events')]
class GamificationEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'event_code', type: 'string', length: 80)]
    private string $eventCode;

    #[ORM\Column(name: 'event_label', type: 'string', length: 160)]
    private string $eventLabel;

    #[ORM\Column(type: 'integer')]
    private int $points;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private User $user;

    public function getId(): int
    {
        return $this->id;
    }

    public function getEventCode(): string
    {
        return $this->eventCode;
    }

    public function setEventCode(string $eventCode): static
    {
        $this->eventCode = $eventCode;
        return $this;
    }

    public function getEventLabel(): string
    {
        return $this->eventLabel;
    }

    public function setEventLabel(string $eventLabel): static
    {
        $this->eventLabel = $eventLabel;
        return $this;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }
}
