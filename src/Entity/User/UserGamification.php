<?php

namespace App\Entity\User;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_gamification')]
class UserGamification
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    private int $id;

    #[ORM\Column(name: 'points_total', type: 'integer')]
    private int $pointsTotal = 0;

    #[ORM\Column(type: 'string', length: 20)]
    private string $level = 'STARTER';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private string|null $badges = null;

    #[ORM\Column(name: 'last_daily_game_at', type: 'date', nullable: true)]
    private \DateTimeInterface|null $lastDailyGameAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User\User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private User $user;

    public function getId(): int
    {
        return $this->id;
    }

    public function getPointsTotal(): int
    {
        return $this->pointsTotal;
    }

    public function setPointsTotal(int $pointsTotal): static
    {
        $this->pointsTotal = $pointsTotal;
        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getBadges(): string|null
    {
        return $this->badges;
    }

    public function setBadges(string|null $badges): static
    {
        $this->badges = $badges;
        return $this;
    }

    public function getLastDailyGameAt(): \DateTimeInterface|null
    {
        return $this->lastDailyGameAt;
    }

    public function setLastDailyGameAt(\DateTimeInterface|null $lastDailyGameAt): static
    {
        $this->lastDailyGameAt = $lastDailyGameAt;
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
